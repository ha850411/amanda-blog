<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MlbWebcastService
{
    public function streams(array $match): array
    {
        $result = ['data' => [], 'matched' => false, 'unavailable' => false, 'stale' => false, 'updated_at' => null, 'source_url' => null];
        if (! config('mlbwebcast.enabled') || ($match['league'] ?? '') !== 'MLB' || count($match['teams'] ?? []) !== 2) {
            return $result;
        }
        $start = CarbonImmutable::parse($match['start_at']);
        if (now()->timestamp > $start->timestamp + config('mlbwebcast.event_lifetime_seconds')) {
            return $result;
        }
        try {
            $catalog = $this->cached('catalog', fn () => $this->parseCatalog($this->get($this->base().'/')['body']));
            $expected = array_map(fn ($team) => $this->slug($team['name']), $match['teams']);
            sort($expected);
            $date = $start->setTimezone(config('mlbwebcast.timezone'));
            $candidates = array_values(array_filter($catalog, fn ($row) => $row['teams'] === $expected
                && $row['date'] === $date->format('Y-m-d') && $row['time'] === $date->format('g:i')));
            // Permanent team channels cannot disambiguate two matching rows.
            if (count($candidates) !== 1) {
                return $result;
            }
            $page = $candidates[0]['url'];
            $streams = $this->cached('detail:'.$page, fn () => $this->parseStreams($this->get($page)['body']));
            foreach ($streams as &$stream) {
                $stream['event_id'] = $match['id'];
                $stream['event_expires_at'] = $start->addSeconds(config('mlbwebcast.event_lifetime_seconds'))->toIso8601String();
                $stream['source_page'] = $page;
            }
            unset($stream);

            return array_replace($result, ['data' => $streams, 'matched' => true, 'source_url' => $page, 'updated_at' => now()->toIso8601String()]);
        } catch (Throwable) {
            return array_replace($result, ['unavailable' => true]);
        }
    }

    public function descriptor(array $player): ?array
    {
        $stream = $player['stream'] ?? [];
        if (! config('mlbwebcast.enabled') || ($stream['resolver'] ?? '') !== 'mlbwebcast'
            || ($stream['event_id'] ?? null) !== ($player['event_id'] ?? null)
            || ! $this->streamUrl($stream['url'] ?? '')
            || ! preg_match('~/stream/[a-z-]+\.html$~D', $stream['url'])
            || strtotime($stream['event_expires_at'] ?? '') <= now()->timestamp) {
            return null;
        }

        return ['resolver' => 'mlbwebcast', 'source_url' => $stream['url'], 'event_id' => $player['event_id'], 'expires_at' => $stream['event_expires_at']];
    }

    public function resolve(array $descriptor): array
    {
        $url = $descriptor['source_url'];
        if (! $this->streamUrl($url)) {
            throw new RuntimeException('Unsupported player URL.');
        }
        $source = $this->cached('source:'.$url, function () use ($url) {
            $html = $this->get($url, 131072)['body'];
            if (! preg_match('/var\s+_d\s*=\s*\[\s*(\d+),\s*[\x27"](\d+)[\x27"],\s*[\x27"]([a-f0-9]{8,64})[\x27"]\s*\]/', $html, $m)) {
                throw new RuntimeException('Unrecognized source page.');
            }
            $channel = $m[1];
            $check = $this->base().'/stream/check_stream.php?'.http_build_query(['id' => $channel, 'ts' => $m[2], 'pt' => $m[3]]);
            $data = json_decode($this->get($check, 16384, $url)['body'], true, 8, JSON_THROW_ON_ERROR);
            $entry = $data['url'] ?? '';
            // This intermediate URL has encoded slashes inside the token path.
            // Validate its complete shape before making its one permitted redirect request.
            if (! is_string($entry) || ! preg_match('~^https://webcast-origin\.b-cdn\.net/bcdn_token=[A-Za-z0-9_-]+&token_path=%2Fplay%2F([A-Za-z0-9_-]+)%2F&expires=(\d+)/play/\1/'.preg_quote($channel, '~').'\.m3u8$~D', $entry, $parts)
                || (int) $parts[2] < now()->timestamp + 20) {
                throw new RuntimeException('Unsupported signed playlist.');
            }
            $redirect = $this->get($entry, 16384, $url, true);
            if ($redirect['status'] !== 302) {
                throw new RuntimeException('Unsupported playlist redirect.');
            }
            $final = $redirect['location'];
            $p = parse_url($final);
            if (! is_array($p) || ($p['scheme'] ?? '') !== 'https'
                || ! in_array($p['host'] ?? '', config('mlbwebcast.stream_hosts', []), true)
                || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)
                || ($p['path'] ?? '') !== '/auth/'.$channel.'.m3u8'
                || ! preg_match('/^token=[A-Za-z0-9_-]+$/D', $p['query'] ?? '')) {
                throw new RuntimeException('Unsupported media origin.');
            }
            $origin = 'https://'.$p['host'];

            return [
                'manifest_url' => $origin.$p['path'].'?'.$p['query'],
                'allowed_prefixes' => [$origin.'/auth/'.$channel.'.m3u8?', $origin.'/hls/'.$channel.'_'],
            ];
        }, config('mlbwebcast.source_cache_seconds', 45));

        return array_merge($descriptor, $source);
    }

    private function cached(string $name, callable $load, ?int $seconds = null): array
    {
        $key = 'sports:mlbwebcast:v1:'.hash('sha256', $name);
        if (Cache::has($key.':backoff')) {
            throw new RuntimeException('Retry later.');
        }

        return Cache::lock($key.':lock', 30)->block(2, function () use ($key, $load, $seconds) {
            if (is_array($data = Cache::get($key))) {
                return $data;
            }
            if (Cache::has($key.':backoff')) {
                throw new RuntimeException('Retry later.');
            }
            try {
                $data = $load();
                Cache::put($key, $data, $seconds ?? config('mlbwebcast.cache_seconds'));

                return $data;
            } catch (Throwable $e) {
                Cache::put($key.':backoff', true, 15);
                throw $e;
            }
        });
    }

    private function get(string $url, int $limit = 1048576, ?string $referer = null, bool $redirect = false): array
    {
        $request = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions(['stream' => true]);
        if ($referer) {
            $request = $request->withHeaders(['Referer' => $referer]);
        }
        $response = $request->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200 && ! ($redirect && $response->status() === 302)) {
                throw new RuntimeException('Source unavailable.');
            }
            $body = '';
            while (! $stream->eof() && strlen($body) <= $limit) {
                $body .= $stream->read(min(8192, $limit + 1 - strlen($body)));
            }
            if (strlen($body) > $limit) {
                throw new RuntimeException('Response too large.');
            }

            return ['body' => $body, 'status' => $response->status(), 'location' => $response->header('Location') ?? ''];
        } finally {
            $stream->close();
        }
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            return new DOMXPath($doc);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parseCatalog(string $html): array
    {
        $xpath = $this->document($html);
        if (! $xpath->query('//*[@id="tableinner"]')->length) {
            throw new RuntimeException('Missing schedule.');
        }
        $rows = [];
        foreach ($xpath->query('//*[@id="tableinner"]/tr') as $row) {
            $teams = [];
            foreach ($xpath->query('.//td[contains(@class,"teamlogo")]/a[@href]', $row) as $link) {
                $path = $this->teamPath($link->getAttribute('href'));
                if ($path) {
                    $teams[] = substr($path, 1, -6);
                }
            }
            if (count($teams) !== 2) {
                continue;
            }
            $date = trim($xpath->query('.//*[contains(@class,"mtdate")]', $row)->item(0)?->textContent ?? '');
            $time = trim($xpath->query('.//td[contains(@class,"matchtime")]', $row)->item(0)?->textContent ?? '');
            $page = $xpath->query('.//td[contains(@class,"teamvs")]/a[@href]', $row)->item(0)?->getAttribute('href');
            if (! $this->teamPath($page ?? '') || ! preg_match('/^[A-Za-z]+ \d{1,2}, \d{4}$/D', $date) || ! preg_match('/^\d{1,2}:\d{2}$/D', $time)) {
                continue;
            }
            sort($teams);
            $rows[] = ['teams' => $teams, 'date' => CarbonImmutable::parse($date, config('mlbwebcast.timezone'))->format('Y-m-d'), 'time' => $time, 'url' => $page];
        }

        return $rows;
    }

    private function parseStreams(string $html): array
    {
        $xpath = $this->document($html);
        $streams = [];
        foreach ($xpath->query('//*[@id="multistmb"]//a[@href]') as $link) {
            $url = $link->getAttribute('href');
            if (! $this->streamUrl($url)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/u', ' ', $link->textContent));
            $name = ['HOME' => '主隊轉播', 'AWAY' => '客隊轉播', 'LINK 3' => '備用 3', 'LINK 4' => '備用 4'][$name] ?? mb_substr($name, 0, 40);
            $streams[$url] = ['url' => $url, 'language' => $name, 'hd' => null, 'number' => count($streams) + 1,
                'provider' => 'MLBWebcast', 'platform' => 'MLBWebcast', 'resolver' => in_array($name, ['主隊轉播', '客隊轉播'], true) ? 'mlbwebcast' : null];
        }

        return array_values($streams);
    }

    private function teamPath(string $url): ?string
    {
        $prefix = $this->base();

        return preg_match('~^'.preg_quote($prefix, '~').'(/[a-z]+(?:-[a-z]+)*-live/)$~D', $url, $m) ? $m[1] : null;
    }

    private function streamUrl(string $url): bool
    {
        return (bool) preg_match('~^'.preg_quote($this->base(), '~').'/stream/[a-z][a-z0-9-]{0,50}\.html$~D', $url);
    }

    private function slug(string $name): string
    {
        $name = strtolower(str_replace('.', '', $name));
        $name = in_array($name, ['athletics', 'sacramento athletics'], true) ? 'oakland athletics' : $name;

        return str_replace(' ', '-', $name);
    }

    private function base(): string
    {
        return rtrim(config('mlbwebcast.base_url'), '/');
    }
}
