<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StreameastHlsService
{
    public function descriptor(array $player): ?array
    {
        $stream = $player['stream'] ?? [];
        $channel = $this->channel($stream['url'] ?? '');
        if (! config('streameast.enabled') || ($stream['resolver'] ?? '') !== 'streameast'
            || ! $channel || empty($player['event_id'])
            || ($stream['event_id'] ?? null) !== $player['event_id']
            || strtotime($stream['event_expires_at'] ?? '') <= now()->timestamp) {
            return null;
        }

        return ['resolver' => 'streameast', 'source_url' => $stream['url'], 'channel' => $channel,
            'event_id' => $player['event_id'], 'expires_at' => $stream['event_expires_at']];
    }

    public function channel(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $this->safeUrl($url) || ! in_array($parts['host'], config('streameast.embed_hosts', []), true)
            || ! preg_match('~^/new-stream-embed/([1-9][0-9]{0,8})$~D', $parts['path'] ?? '', $m)
            || ! in_array($parts['query'] ?? '', ['', 'ad=111', '3newa=22'], true)) {
            return null;
        }

        return $m[1];
    }

    public function resolve(array $descriptor): array
    {
        if (($descriptor['channel'] ?? '') !== $this->channel($descriptor['source_url'] ?? '')
            || strtotime($descriptor['expires_at'] ?? '') <= now()->timestamp) {
            throw new RuntimeException('Unsupported Streameast source.');
        }
        $key = 'sports:streameast:hls:v1:'.hash('sha256', $descriptor['source_url']);
        $source = Cache::lock($key.':lock', 30)->block(2, function () use ($key, $descriptor) {
            if (is_array($cached = Cache::get($key))) {
                return $cached;
            }
            $previous = Cache::get($key.':last-good');
            $usablePrevious = is_array($previous) && $this->allows($previous['manifest_url'] ?? '', $descriptor, 'playlist');
            if (Cache::has($key.':backoff')) {
                if ($usablePrevious) {
                    return $previous;
                }
                throw new RuntimeException('Retry later.');
            }
            try {
                $html = $this->get($descriptor['source_url'], 262144);
                $entries = [];
                // Only read the two literal source forms observed on the embed.
                // Decoding base64 is data parsing; never execute upstream scripts.
                preg_match_all('~(?:const\s+source\s*=|\bsource\s*:)\s*([\'"])(https://[^\'"\s<>]+)\1~', $html, $plain);
                foreach ($plain[2] as $url) {
                    $entries[$url] = true;
                }
                preg_match_all('~\bsource\s*:\s*window\.atob\(\s*([\'"])([A-Za-z0-9+/=]+)\1\s*\)~', $html, $encoded);
                foreach ($encoded[2] as $value) {
                    if (($url = base64_decode($value, true)) !== false) {
                        $entries[$url] = true;
                    }
                }
                if (count($entries) !== 1 || ! $this->entryAllowed($entry = array_key_first($entries), $descriptor)) {
                    throw new RuntimeException('Unrecognized Streameast player.');
                }
                $manifest = $entry;
                if (! $this->allows($entry, $descriptor, 'playlist')) {
                    // The observed entry advertises one rendition. Resolve its
                    // current CDN on each cache refresh; never pin an old host
                    // into a long-lived client child-playlist ticket.
                    $body = $this->get($entry, 262144, true);
                    if (! str_starts_with(ltrim($body), '#EXTM3U')
                        || substr_count($body, '#EXT-X-STREAM-INF:') !== 1
                        || preg_match('/\bURI=/', $body)) {
                        throw new RuntimeException('Unsupported Streameast master playlist.');
                    }
                    $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), fn ($line) => $line !== '' && $line[0] !== '#'));
                    if (count($lines) !== 1 || ! $this->allows($lines[0], $descriptor, 'playlist')) {
                        throw new RuntimeException('Unsupported Streameast rendition.');
                    }
                    $manifest = $lines[0];
                }
                $source = ['manifest_url' => $manifest];
                Cache::put($key, $source, config('streameast.source_cache_seconds', 45));
                Cache::put($key.':last-good', $source, config('streameast.source_stale_seconds', 180));

                return $source;
            } catch (Throwable $error) {
                Cache::put($key.':backoff', true, 15);
                // Keep fetching fresh media playlists from the last verified
                // origin during a brief entry failure. Never extend its TTL.
                if ($usablePrevious) {
                    return $previous;
                }
                throw $error;
            }
        });
        // Config changes revoke cached origins as well as fresh responses.
        if (! $this->allows($source['manifest_url'] ?? '', $descriptor, 'playlist')) {
            throw new RuntimeException('Unsupported cached Streameast source.');
        }

        return array_merge($descriptor, $source);
    }

    public function allows(string $url, array $source, string $kind): bool
    {
        if (! $this->safeUrl($url)) {
            return false;
        }
        $parts = parse_url($url);
        $channel = $source['channel'] ?? '';
        if (! preg_match('/^[1-9][0-9]{0,8}$/D', $channel) || isset($parts['query'])) {
            return false;
        }
        if ($kind === 'playlist') {
            if (! in_array($parts['host'], config('streameast.playlist_hosts', []), true)) {
                return false;
            }
            foreach (config('streameast.media_hosts', []) as $mediaHost) {
                if (($parts['path'] ?? '') === '/playlist/'.$channel.'/'.$mediaHost.'/caxi') {
                    return true;
                }
            }
        } elseif ($kind === 'segment' && in_array($parts['host'], config('streameast.media_hosts', []), true)) {
            return (bool) preg_match('~^/scripts/'.preg_quote(base64_encode($channel), '~').'/p[0-9]+_[0-9]+\.(?:txt|ts)$~D', $parts['path'] ?? '');
        }

        return false;
    }

    public function playlist(string $url, array $source, int $limit): string
    {
        if (! $this->allows($url, $source, 'playlist')) {
            throw new RuntimeException('Unsupported Streameast playlist.');
        }

        return $this->get($url, $limit, true);
    }

    private function entryAllowed(string $url, array $source): bool
    {
        if ($this->allows($url, $source, 'playlist')) {
            return true;
        }
        if (! $this->safeUrl($url)) {
            return false;
        }
        $parts = parse_url($url);

        return in_array($parts['host'], config('streameast.entry_hosts', []), true)
            && ! isset($parts['query'])
            && ($parts['path'] ?? '') === '/playlist/'.$source['channel'].'/load-playlist';
    }

    private function safeUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['fragment'])
            && ! preg_match('/[\\\\\x00-\x20]/', $url)
            && ! str_contains($parts['path'] ?? '', '%')
            && ! preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $parts['path'] ?? '');
    }

    private function get(string $url, int $limit, bool $playlist = false): string
    {
        $request = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions(['stream' => true]);
        if ($playlist) {
            $request = $request->withHeaders(['Origin' => 'https://gooz.aapmains.net', 'Referer' => 'https://gooz.aapmains.net/']);
        }
        $response = $request->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 200) {
                throw new RuntimeException('Streameast unavailable.');
            }
            $body = '';
            while (! $stream->eof() && strlen($body) <= $limit) {
                $body .= $stream->read(min(8192, $limit + 1 - strlen($body)));
            }
            if (strlen($body) > $limit) {
                throw new RuntimeException('Response too large.');
            }

            return $body;
        } finally {
            $stream->close();
        }
    }
}
