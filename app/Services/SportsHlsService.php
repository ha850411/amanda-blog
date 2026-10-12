<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

class SportsHlsService
{
    public function __construct(private SportsHlsTransport $transport) {}

    public function source(array $player): ?array
    {
        $source = config('sports_player.hls_sources', [])[$player['stream']['url'] ?? ''] ?? null;
        if (! is_array($source) || ($source['event_id'] ?? null) !== ($player['event_id'] ?? null)
            || strtotime($source['expires_at'] ?? '') <= now()->timestamp
            || ! $this->allowed($source['manifest_url'] ?? '', $source)) {
            return null;
        }

        return $source;
    }

    public function allowed(string $url, array $source): bool
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/[\\\\\x00-\x20]/', $url)) {
            return false;
        }
        $path = $parts['path'] ?? '';
        // No encoded separators, double decoding, or dot segments in upstream paths.
        if (str_contains($path, '%') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            return false;
        }
        foreach ($source['allowed_prefixes'] ?? [] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function load(array $source, string $url, string $kind): string
    {
        if ($kind !== 'playlist' || ! $this->allowed($url, $source)) {
            throw new RuntimeException('Unsupported resource.');
        }
        // A fixed number of file-cache slots bounds disk use, including expired files.
        // Only tiny playlists ever pass through this server; video stays on the CDN.
        $slots = 8;
        $key = 'hls:'.hash('sha256', $source['manifest_url']).':'.$kind.':'.(hexdec(substr(hash('sha256', $url), 0, 7)) % $slots);
        $cache = Cache::store(config('sports_player.manifest_cache_store', 'sports_manifests'));
        $entry = $cache->get($key);
        if (($entry['url'] ?? null) === $url) {
            if (isset($entry['failed'])) {
                throw new RuntimeException('Retry later.');
            }

            return $entry['body'];
        }

        return $cache->lock($key.':lock', 12)->block(2, function () use ($cache, $key, $url) {
            $entry = $cache->get($key);
            if (($entry['url'] ?? null) === $url) {
                if (isset($entry['failed'])) {
                    throw new RuntimeException('Retry later.');
                }

                return $entry['body'];
            }
            try {
                $body = $this->transport->get($url, 262144);
                if (! str_starts_with(ltrim($body), '#EXTM3U')) {
                    throw new RuntimeException('Invalid upstream response.');
                }
                $cache->put($key, ['url' => $url, 'body' => $body], 1);

                return $body;
            } catch (Throwable $e) {
                $cache->put($key, ['url' => $url, 'failed' => true], 2);
                throw $e;
            }
        });
    }

    public function rewrite(string $body, string $base, array $source, string $token): string
    {
        $nextPlaylist = false;
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_starts_with($line, '#')) {
                $line = $this->resourceUrl($line, $base, $nextPlaylist ? 'playlist' : 'segment', $source, $token);
                $nextPlaylist = false;

                continue;
            }
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $nextPlaylist = true;
            }
            if (str_contains($line, 'URI=')) {
                $kind = preg_match('/^#EXT-X-(?:MEDIA|I-FRAME-STREAM-INF|RENDITION-REPORT):/', $line) ? 'playlist'
                    : (preg_match('/^#EXT-X-(?:SESSION-)?KEY:/', $line) ? 'key' : 'segment');
                $line = preg_replace_callback('/\bURI="([^"]+)"/', fn ($m) => 'URI="'.$this->resourceUrl($m[1], $base, $kind, $source, $token).'"', $line);
                if (preg_match('/\bURI=(?!")/', $line)) {
                    throw new RuntimeException('Invalid playlist URI.');
                }
            }
        }

        return implode("\n", $lines);
    }

    private function resourceUrl(string $uri, string $base, string $kind, array $source, string $token): string
    {
        $url = (string) UriResolver::resolve(new Uri($base), new Uri($uri));
        if (! $this->allowed($url, $source)) {
            throw new RuntimeException('Unsupported playlist URI.');
        }
        if ($kind !== 'playlist') {
            // Never fall back to relaying fragments/keys: it creates unbounded egress costs.
            return $url;
        }
        $ticket = Crypt::encryptString(json_encode([
            'url' => $url, 'kind' => $kind, 'player' => $token, 'expires' => now()->timestamp + config('sports_player.lifetime_seconds', 21600),
        ], JSON_THROW_ON_ERROR));

        return route('sports.hls.resource', ['token' => $token, 'ticket' => $ticket], false);
    }

    public function resource(string $ticket, string $token, array $source): ?array
    {
        try {
            if (strlen($ticket) > 8192) {
                return null;
            }
            $value = json_decode(Crypt::decryptString($ticket), true, 16, JSON_THROW_ON_ERROR);
            if (($value['player'] ?? null) !== $token || ($value['expires'] ?? 0) <= now()->timestamp
                || ($value['kind'] ?? null) !== 'playlist'
                || ! is_string($value['url'] ?? null) || ! $this->allowed($value['url'], $source)) {
                return null;
            }

            return $value;
        } catch (Throwable) {
            return null;
        }
    }
}
