<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StreameastService
{
    public function streams(array $match): array
    {
        $result = ['data' => [], 'matched' => false, 'unavailable' => false, 'stale' => false, 'updated_at' => null, 'source_url' => null];
        if (! config('streameast.enabled')) {
            return $result;
        }
        $paths = config('streameast.catalogs.'.$match['category'], []);
        // Avoid querying college/WNBA catalogs for a positively identified NBA game.
        if ($match['league'] === 'NBA') {
            $paths = ['/nbastreams3'];
        }
        $candidates = [];
        foreach ($paths as $path) {
            $catalog = $this->fetch($path, fn ($html) => $this->parseCatalog($html));
            $result['unavailable'] = $result['unavailable'] || $catalog['unavailable'];
            $result['stale'] = $result['stale'] || $catalog['stale'];
            foreach ($catalog['data'] as $event) {
                if ($this->sameTeams($match, $event['title'])) {
                    $candidates[$event['path']] = $event;
                }
            }
        }
        // Lists are only a discovery hint. Verify the event's own SportsEvent
        // metadata, including start time, before attaching any player links.
        foreach (array_slice($candidates, 0, 3) as $candidate) {
            $detail = $this->fetch($candidate['path'], fn ($html) => $this->parseEvent($html));
            $result['unavailable'] = $result['unavailable'] || $detail['unavailable'];
            if (! $detail['data']) {
                continue;
            }
            $event = $detail['data'];
            if (! $this->sameTeams($match, $event['title'])
                || abs(CarbonImmutable::parse($match['start_at'])->timestamp - $event['start']) > config('streameast.match_tolerance_seconds', 900)) {
                continue;
            }
            $result['matched'] = true;
            $result['data'] = $event['streams'];
            $result['source_url'] = rtrim(config('streameast.base_url'), '/').$candidate['path'];
            $result['updated_at'] = $detail['updated_at'];
            $result['stale'] = $result['stale'] || $detail['stale'];

            return $result;
        }

        return $result;
    }

    private function fetch(string $path, callable $parse): array
    {
        $key = 'sports:streameast:v1:'.hash('sha256', config('streameast.base_url').$path);
        $now = CarbonImmutable::now()->timestamp;
        $cached = Cache::get($key);
        if ($cached && $now - $cached['timestamp'] < config('streameast.cache_seconds', 120)) {
            return $this->result($cached);
        }
        try {
            if (Cache::has($key.':backoff')) {
                throw new RuntimeException('Retry later.');
            }
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->get(rtrim(config('streameast.base_url'), '/').$path);
            if (! $response->successful() || strlen($response->body()) > 4_000_000) {
                throw new RuntimeException('Streameast unavailable.');
            }
            $entry = ['data' => $parse($response->body()), 'timestamp' => $now];
            Cache::put($key, $entry, config('streameast.stale_seconds', 900));
            Cache::forget($key.':backoff');

            return $this->result($entry);
        } catch (Throwable) {
            if (! Cache::has($key.':backoff')) {
                Cache::put($key.':backoff', true, 15);
            }
            if ($cached && $now - $cached['timestamp'] < config('streameast.stale_seconds', 900)) {
                return $this->result($cached, true);
            }

            return ['data' => [], 'stale' => false, 'unavailable' => true, 'updated_at' => null];
        }
    }

    private function result(array $entry, bool $stale = false): array
    {
        return [
            'data' => $entry['data'], 'stale' => $stale, 'unavailable' => false,
            'updated_at' => CarbonImmutable::createFromTimestampUTC($entry['timestamp'])->toIso8601String(),
        ];
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parseCatalog(string $html): array
    {
        $xpath = $this->document($html);
        if ($xpath->query('//*[@id="GelecekMaclar"]')->length === 0) {
            throw new RuntimeException('Unrecognized Streameast catalog.');
        }
        $events = [];
        foreach ($xpath->query('//*[@id="GelecekMaclar"]//li[@data-league]//a[@href]') as $link) {
            $path = $this->eventPath($link->getAttribute('href'));
            $title = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " MacBaslik ")]', $link)->item(0)?->textContent;
            if ($path && $title) {
                $events[$path] = ['path' => $path, 'title' => trim(preg_replace('/\s+/u', ' ', $title))];
            }
        }

        return array_values($events);
    }

    private function eventPath(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || (isset($parts['host']) && strtolower($parts['host']) !== parse_url(config('streameast.base_url'), PHP_URL_HOST))
            || (isset($parts['scheme']) && $parts['scheme'] !== 'https')) {
            return null;
        }
        $path = $parts['path'] ?? '';

        return preg_match('~^/[a-z0-9-]+/[a-z0-9-]+/[0-9]+$~D', $path) ? $path : null;
    }

    private function parseEvent(string $html): array
    {
        $xpath = $this->document($html);
        $metadata = null;
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = json_decode($script->textContent, true);
            if (is_array($json) && ($json['@type'] ?? '') === 'SportsEvent') {
                $metadata = $json;
                break;
            }
        }
        if (! is_string($metadata['name'] ?? null) || ! is_string($metadata['startDate'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T.+(?:Z|[+-]\d{2}:\d{2})$/D', $metadata['startDate'])) {
            throw new RuntimeException('Missing event identity or timezone.');
        }
        $streams = [];
        // Parse only the literal URL template used by the observed server switcher.
        // Never evaluate third-party scripts or import their page markup.
        $template = null;
        foreach ($xpath->query('//script[not(@src)]') as $script) {
            if (preg_match('~getElementById\([\'"]wp_player[\'"]\)\.src\s*=\s*[\'"](https://[^\'"\s]+)[\'"]\s*\+\s*streamId\s*\+\s*[\'"]([^\'"]*)[\'"]~', $script->textContent, $parts)) {
                $template = [$parts[1], $parts[2]];
                break;
            }
        }
        if ($template) {
            foreach ($xpath->query('//*[@id="Alternatifler"]//*[starts-with(@id, "stream-btn-")]') as $button) {
                if (! preg_match('/^stream-btn-([0-9]+)$/D', $button->getAttribute('id'), $id)
                    || ! preg_match('/^window\.changeStream\('.$id[1].'\);?$/D', trim($button->getAttribute('onclick')))) {
                    continue;
                }
                $url = $template[0].$id[1].$template[1];
                if ($this->safeEmbed($url)) {
                    $streams[parse_url($url, PHP_URL_PATH)] = $this->stream($url, trim($button->textContent), count($streams) + 1);
                }
            }
        }
        $frame = $xpath->query('//iframe[@id="wp_player"]')->item(0);
        $url = $frame?->getAttribute('src');
        if ($url && $this->safeEmbed($url)) {
            $streams[parse_url($url, PHP_URL_PATH)] ??= $this->stream($url, '預設播放器', count($streams) + 1);
        }

        return [
            'title' => $metadata['name'],
            'start' => CarbonImmutable::parse($metadata['startDate'])->timestamp,
            'streams' => array_values($streams),
        ];
    }

    private function safeEmbed(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), config('streameast.embed_hosts', []), true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    private function stream(string $url, string $name, int $number): array
    {
        return ['url' => $url, 'language' => mb_substr($name, 0, 80), 'hd' => null, 'number' => $number, 'provider' => 'Streameast', 'platform' => 'Streameast'];
    }

    private function sameTeams(array $match, string $title): bool
    {
        $normalize = function (string $name): string {
            $name = mb_strtolower(trim($name));
            $name = str_replace(['la clippers', 'oakland athletics', 'sacramento athletics'], ['los angeles clippers', 'athletics', 'athletics'], $name);

            return preg_replace('/[^\p{L}\p{N}]/u', '', $name);
        };
        $parts = preg_split('/\s+(?:vs\.?|v\.?|at|-)\s+/i', $title);
        if (count($match['teams']) === 2 && count($parts) === 2) {
            $expected = array_map($normalize, array_column($match['teams'], 'name'));
            $actual = array_map($normalize, $parts);
            sort($expected);
            sort($actual);

            return $expected === $actual;
        }

        return $normalize($match['title']) === $normalize($title);
    }
}
