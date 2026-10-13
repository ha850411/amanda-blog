<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class GksService
{
    public function __construct(private GksTransport $transport) {}

    public function descriptor(array $player): ?array
    {
        $url = $player['stream']['url'] ?? '';
        if (! config('gks.enabled') || ! $this->slot($url)
            || strtotime($player['event_expires_at'] ?? '') <= now()->timestamp) {
            return null;
        }

        return ['resolver' => 'gks', 'source_url' => $url, 'event_id' => $player['event_id'], 'expires_at' => $player['event_expires_at']];
    }

    public function resolve(array $descriptor): array
    {
        $slot = $this->slot($descriptor['source_url']);
        if (! $slot) {
            throw new RuntimeException('Unsupported GKS source.');
        }
        $cache = Cache::store(config('sports_player.manifest_cache_store', 'sports_manifests'));
        $key = 'gks:source:v1:'.hash('sha256', $descriptor['source_url']);
        $entry = $cache->get($key);
        if (! is_array($entry)) {
            $entry = $cache->lock($key.':lock', 12)->block(2, function () use ($cache, $key, $slot) {
                if (is_array($entry = $cache->get($key))) {
                    return $entry;
                }
                try {
                    $wire = '';
                    foreach ($slot as $i => $value) {
                        $wire .= chr(10 + 8 * $i).$this->varint(strlen($value)).$value;
                    }
                    $response = $this->transport->handshake($wire);
                    $url = $this->decode($response['body'], $response['key']);
                    $path = $slot[0] === 'hotel' ? 'hotel/stream/'.preg_quote($slot[1], '~') : 'rtmp/stream/[A-Za-z0-9_-]{16,512}';
                    if (! preg_match('~^https://lb[0-9]+\.strmd\.st/secure/[A-Za-z0-9_-]{16,128}/'.$path.'/'.preg_quote($slot[2], '~').'/playlist\.m3u8$~D', $url)) {
                        throw new RuntimeException('Unsupported GKS manifest.');
                    }
                    $entry = ['manifest_url' => $url, 'playlist_base' => substr($url, 0, -strlen('playlist.m3u8'))];
                    $cache->put($key, $entry, config('gks.source_cache_seconds', 45));

                    return $entry;
                } catch (Throwable $e) {
                    $cache->put($key, ['failed' => true], 15);
                    throw $e;
                }
            });
        }
        if (isset($entry['failed'])) {
            throw new RuntimeException('Retry later.');
        }

        return array_merge($descriptor, $entry);
    }

    public function allows(string $url, array $source, string $kind): bool
    {
        $base = $source['playlist_base'] ?? '';
        if ($kind === 'playlist') {
            return $base !== '' && in_array($url, [$base.'playlist.m3u8', $base.'high/mono.m3u8', $base.'low/mono.m3u8'], true);
        }
        // Media only travels directly to the observed CDN hosts. Never fetch it here.
        $parts = parse_url($url);

        return $kind === 'segment' && in_array($parts['host'] ?? '', config('gks.media_hosts', []), true)
            && str_starts_with($parts['path'] ?? '', '/');
    }

    public function mediaLocations(array $urls, array $source): array
    {
        $origin = 'https://'.parse_url($source['manifest_url'], PHP_URL_HOST);
        $urls = array_values(array_unique($urls));
        if (count($urls) > 8) {
            throw new RuntimeException('Too many media redirects.');
        }
        foreach ($urls as $url) {
            if (! preg_match('~^'.preg_quote($origin, '~').'/m/[A-Za-z0-9_-]{100,1500}\.ts$~D', $url)) {
                throw new RuntimeException('Unsupported media redirect.');
            }
        }
        if (! $urls) {
            return [];
        }
        $cache = Cache::store(config('sports_player.manifest_cache_store', 'sports_manifests'));
        $prefix = 'gks:media:v1:'.hash('sha256', $source['source_url']);

        return $cache->lock($prefix.':lock', 8)->block(2, function () use ($urls, $source, $cache, $prefix) {
            $locations = $missing = [];
            foreach ($urls as $url) {
                // Bound expired file-cache entries even during a long live stream.
                $key = $prefix.':'.(hexdec(substr(hash('sha256', $url), 0, 7)) % 64);
                $entry = $cache->get($key);
                if (($entry['url'] ?? '') === $url && $this->allows($entry['location'] ?? '', $source, 'segment')) {
                    $locations[$url] = $entry['location'];
                } else {
                    $missing[$url] = $key;
                }
            }
            if ($missing) {
                foreach ($this->transport->redirects(array_keys($missing)) as $url => $location) {
                    if (! isset($missing[$url]) || ! $this->allows($location, $source, 'segment')
                        || ! filter_var($location, FILTER_VALIDATE_URL) || parse_url($location, PHP_URL_SCHEME) !== 'https'
                        || parse_url($location, PHP_URL_USER) !== null || parse_url($location, PHP_URL_PORT) !== null) {
                        throw new RuntimeException('Unsupported media destination.');
                    }
                    $locations[$url] = $location;
                    $cache->put($missing[$url], ['url' => $url, 'location' => $location], 90);
                }
            }
            if (count($locations) !== count($urls)) {
                throw new RuntimeException('Missing media destination.');
            }

            return $locations;
        });
    }

    public function decode(string $body, string $key): string
    {
        if (strlen($body) > 16384 || ! preg_match('/^[A-Za-z]{32}$/D', $key) || ($body[0] ?? '') !== "\x0a") {
            throw new RuntimeException('Invalid GKS response.');
        }
        $offset = 1;
        $size = 0;
        for ($shift = 0; $shift <= 21; $shift += 7) {
            if (! isset($body[$offset])) {
                throw new RuntimeException('Truncated GKS response.');
            }
            $byte = ord($body[$offset++]);
            $size |= ($byte & 127) << $shift;
            if (($byte & 128) === 0) {
                break;
            }
        }
        if (($byte & 128) || $size < 40 || $size !== strlen($body) - $offset) {
            throw new RuntimeException('Invalid GKS payload length.');
        }
        $text = substr($body, $offset);
        // The public player decodes printable rotation, Base64, then an authenticated
        // ChaCha20-Poly1305 envelope: nonce (12), ciphertext, authentication tag (16).
        foreach ([71, 47] as $rotation) {
            $base64 = '';
            foreach (str_split($text) as $char) {
                $code = ord($char);
                if ($code < 33 || $code > 126) {
                    throw new RuntimeException('Invalid GKS encoding.');
                }
                $base64 .= chr(($code - 33 + $rotation) % 94 + 33);
            }
            $bytes = base64_decode($base64, true);
            if ($bytes === false || strlen($bytes) < 29) {
                continue;
            }
            $url = openssl_decrypt(substr($bytes, 12, -16), 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, -16), '');
            if (is_string($url)) {
                return $url;
            }
        }

        throw new RuntimeException('GKS authentication failed.');
    }

    private function slot(string $url): ?array
    {
        if (! preg_match('~^https://embed\.st/embed/([a-z]+)/([a-zA-Z0-9_-]{1,160})/([1-9][0-9]?)$~D', $url, $m)
            || ! in_array($m[1], config('gks.providers', []), true)
            || ! in_array((int) $m[3], config('gks.stream_numbers', []), true)) {
            return null;
        }

        return array_slice($m, 1);
    }

    private function varint(int $value): string
    {
        $result = '';
        do {
            $byte = $value & 127;
            $value >>= 7;
            $result .= chr($byte | ($value ? 128 : 0));
        } while ($value);

        return $result;
    }
}
