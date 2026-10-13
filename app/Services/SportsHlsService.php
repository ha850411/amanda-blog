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

    public function source(array $player, bool $resolve = false): ?array
    {
        $gks = app(GksService::class);
        if ($descriptor = $gks->descriptor($player)) {
            return $resolve ? $gks->resolve($descriptor) : $descriptor;
        }
        $golf = app(GolfHlsService::class);
        if ($descriptor = $golf->descriptor($player)) {
            return $resolve ? $golf->resolve($descriptor) : $descriptor;
        }
        $webcast = app(MlbWebcastService::class);
        if ($descriptor = $webcast->descriptor($player)) {
            return $resolve ? $webcast->resolve($descriptor) : $descriptor;
        }
        $streameast = app(StreameastHlsService::class);
        if ($descriptor = $streameast->descriptor($player)) {
            return $resolve ? $streameast->resolve($descriptor) : $descriptor;
        }
        $source = config('sports_player.hls_sources', [])[$player['stream']['url'] ?? ''] ?? null;
        if (! is_array($source) || ($source['event_id'] ?? null) !== ($player['event_id'] ?? null)
            || strtotime($source['expires_at'] ?? '') <= now()->timestamp
            || ! $this->allowed($source['manifest_url'] ?? '', $source)) {
            return null;
        }

        return $source;
    }

    public function allowed(string $url, array $source, string $kind = 'playlist'): bool
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
        if (($source['resolver'] ?? '') === 'gks') {
            return app(GksService::class)->allows($url, $source, $kind);
        }
        if (($source['resolver'] ?? '') === 'golf') {
            return app(GolfHlsService::class)->allows($url, $source, $kind);
        }
        if (($source['resolver'] ?? '') === 'streameast') {
            return app(StreameastHlsService::class)->allows($url, $source, $kind);
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
        $key = 'hls:'.hash('sha256', $source['source_url'] ?? $source['manifest_url']).':'.$kind.':'.(hexdec(substr(hash('sha256', $url), 0, 7)) % $slots);
        $hotel = ($source['resolver'] ?? '') === 'gks' && str_contains($source['source_url'], '/embed/hotel/');
        $lastGoodKey = $hotel ? 'gks:last-playlist:'.hash('sha256', $source['source_url']) : null;
        $cache = Cache::store(config('sports_player.manifest_cache_store', 'sports_manifests'));
        $entry = $cache->get($key);
        if (($entry['url'] ?? null) === $url) {
            if (isset($entry['failed'])) {
                throw new RuntimeException('Retry later.');
            }

            return $entry['body'];
        }

        return $cache->lock($key.':lock', 12)->block(2, function () use ($cache, $key, $url, $source, $hotel, $lastGoodKey) {
            $entry = $cache->get($key);
            if (($entry['url'] ?? null) === $url) {
                if (isset($entry['failed'])) {
                    throw new RuntimeException('Retry later.');
                }

                return $entry['body'];
            }
            try {
                $body = match ($source['resolver'] ?? '') {
                    'gks', 'golf' => app(GksTransport::class)->playlist($url, 262144),
                    'streameast' => app(StreameastHlsService::class)->playlist($url, $source, 262144),
                    default => $this->transport->get($url, 262144),
                };
                if (! str_starts_with(ltrim($body), '#EXTM3U')) {
                    throw new RuntimeException('Invalid upstream response.');
                }
                if ($hotel) {
                    $lines = explode("\n", $body);
                    $media = [];
                    foreach ($lines as $line) {
                        if (str_starts_with(trim($line), '/m/')) {
                            $media[trim($line)] = (string) UriResolver::resolve(new Uri($url), new Uri(trim($line)));
                        }
                    }
                    $locations = app(GksService::class)->mediaLocations(array_values($media), $source);
                    foreach ($lines as &$line) {
                        if (isset($media[trim($line)])) {
                            $line = $locations[$media[trim($line)]];
                        }
                    }
                    unset($line);
                    $body = implode("\n", $lines);
                    $cache->put($lastGoodKey, $body, 12);
                }
                $cache->put($key, ['url' => $url, 'body' => $body], 1);

                return $body;
            } catch (Throwable $e) {
                // A slow HEAD must not discard already verified, still-current CDN
                // metadata. Do not extend its original 12-second lifetime on failure.
                if ($lastGoodKey && is_string($previous = $cache->get($lastGoodKey))) {
                    $cache->put($key, ['url' => $url, 'body' => $previous], 1);

                    return $previous;
                }
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
        if (! $this->allowed($url, $source, $kind)) {
            throw new RuntimeException('Unsupported playlist URI.');
        }
        if ($kind !== 'playlist') {
            // User requires direct CDN media. Never relay fragments/keys, even as a fallback.
            return $url;
        }
        $value = [
            'url' => $url, 'kind' => $kind, 'player' => $token, 'expires' => now()->timestamp + config('sports_player.lifetime_seconds', 21600),
        ];
        if (($source['resolver'] ?? '') === 'gks') {
            // Bind the rendition, not an expiring upstream signature. Every request
            // resolves it against the current source, so viewers survive token refresh.
            $value['variant'] = substr($url, strlen($source['playlist_base']));
            unset($value['url']);
        }
        $ticket = Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));

        return route('sports.hls.resource', ['token' => $token, 'ticket' => $ticket], false);
    }

    public function resource(string $ticket, string $token, array $source): ?array
    {
        try {
            if (strlen($ticket) > 8192) {
                return null;
            }
            $value = json_decode(Crypt::decryptString($ticket), true, 16, JSON_THROW_ON_ERROR);
            if (($source['resolver'] ?? '') === 'gks' && in_array($value['variant'] ?? null, ['playlist.m3u8', 'high/mono.m3u8', 'low/mono.m3u8'], true)) {
                $value['url'] = $source['playlist_base'].$value['variant'];
            }
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
