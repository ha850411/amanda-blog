<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class GolfHlsService
{
    public function __construct(private GksTransport $transport, private GksService $gks) {}

    public function descriptor(array $player): ?array
    {
        $url = $player['stream']['url'] ?? '';
        if (! config('gks.enabled') || ! config('gks.golf_enabled')
            || ! $this->slot($url) || empty($player['event_id'])
            || strtotime($player['event_expires_at'] ?? '') <= now()->timestamp) {
            return null;
        }

        return ['resolver' => 'golf', 'source_url' => $url, 'event_id' => $player['event_id'], 'expires_at' => $player['event_expires_at']];
    }

    public function resolve(array $descriptor): array
    {
        $slot = $this->slot($descriptor['source_url'] ?? '');
        if (! $slot || strtotime($descriptor['expires_at'] ?? '') <= now()->timestamp) {
            throw new RuntimeException('Unsupported GOLF source.');
        }
        $cache = Cache::store(config('sports_player.manifest_cache_store', 'sports_manifests'));
        $key = 'golf:source:v1:'.hash('sha256', $descriptor['source_url']);
        $entry = $cache->get($key);
        if (! is_array($entry)) {
            $entry = $cache->lock($key.':lock', 35)->block(2, function () use ($cache, $key, $slot, $descriptor) {
                if (is_array($entry = $cache->get($key))) {
                    return $entry;
                }
                try {
                    // Mapping metadata outlives the signed URL. Never cache video
                    // here or manufacture an INGEST slug from the event title.
                    $target = $cache->get($key.':target');
                    if (! is_array($target)) {
                        $target = $this->target($descriptor['source_url'], $slot);
                        $cache->put($key.':target', $target, config('gks.golf_mapping_cache_seconds', 120));
                    }
                    $wire = '';
                    foreach ($target as $i => $value) {
                        $size = strlen($value);
                        $length = '';
                        do {
                            $byte = $size & 127;
                            $size >>= 7;
                            $length .= chr($byte | ($size ? 128 : 0));
                        } while ($size);
                        $wire .= chr(10 + 8 * $i).$length.$value;
                    }
                    $response = $this->transport->handshake($wire);
                    $url = $this->gks->decode($response['body'], $response['key']);
                    if (! preg_match('~^https://lb[0-9]+\.strmd\.st/secure/[A-Za-z0-9_-]{16,128}/'
                        .preg_quote($target[0].'/stream/'.$target[1].'/'.$target[2], '~').'/playlist\.m3u8$~D', $url)) {
                        throw new RuntimeException('Unexpected GOLF manifest.');
                    }
                    $entry = ['manifest_url' => $url, 'media_origin' => 'https://'.parse_url($url, PHP_URL_HOST)];
                    $cache->put($key, $entry, config('gks.source_cache_seconds', 45));

                    return $entry;
                } catch (Throwable $error) {
                    $cache->put($key, ['failed' => true], 15);
                    throw $error;
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
        if (! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\\\\\x00-\x20]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if ($kind === 'playlist') {
            return $url === ($source['manifest_url'] ?? '');
        }

        return $kind === 'segment' && 'https://'.($parts['host'] ?? '') === ($source['media_origin'] ?? '')
            && preg_match('~^lb[0-9]+\.strmd\.st$~D', $parts['host'] ?? '')
            && preg_match('~^/m/[A-Za-z0-9_-]{100,1500}\.ts$~D', $parts['path'] ?? '');
    }

    private function slot(string $url): ?array
    {
        return preg_match('~^https://embed\.st/embed/golf/([1-9][0-9]{0,8})/([12])$~D', $url, $m)
            ? ['golf', $m[1], $m[2]] : null;
    }

    private function target(string $url, array $slot): array
    {
        $html = $this->transport->page($url, 1048576);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Invalid GOLF page.');
        }
        $frames = [];
        foreach ($document->getElementsByTagName('iframe') as $frame) {
            $src = $frame->getAttribute('src');
            // The known advertising frame is not part of the playback chain.
            if (in_array($src, ['/ad.html', 'https://embed.st/ad.html'], true)) {
                continue;
            }
            if (! preg_match('~^https://rockylive\.st/source/streamed1\.php\?hd=[1-9][0-9]{0,8}&id='
                .preg_quote($slot[1], '~').'&no='.preg_quote($slot[2], '~').'$~D', $src)) {
                throw new RuntimeException('Unrecognized GOLF frame.');
            }
            $frames[] = $src;
        }
        if (count($frames) > 1) {
            throw new RuntimeException('Ambiguous GOLF player.');
        }
        if (! $frames) {
            // Also observed: the standard player directly requests GOLF HLS.
            // Its current 404s remain upstream errors, not a guessed fallback.
            foreach ($document->getElementsByTagName('script') as $script) {
                if ($script->getAttribute('src') === 'https://strmd.b-cdn.net/js/bundle-jw.js'
                    && $document->getElementById('player')) {
                    return $slot;
                }
            }
            throw new RuntimeException('Unrecognized GOLF player.');
        }
        $page = $this->transport->page($frames[0], 65536);
        preg_match_all('~\bplayerFrame\.src\s*=\s*atob\(\s*([\'"])([A-Za-z0-9+/=]{1,2048})\1\s*\)~', $page, $matches);
        if (count($matches[2]) !== 1) {
            throw new RuntimeException('Unrecognized GOLF target.');
        }
        $target = base64_decode($matches[2][0], true);
        if (! is_string($target) || ! preg_match('~^https://embed\.st/embed/ingest/([A-Za-z0-9_-]{1,160})/'
            .preg_quote($slot[2], '~').'$~D', $target, $m)) {
            throw new RuntimeException('Unsupported GOLF target.');
        }

        return ['ingest', $m[1], $slot[2]];
    }
}
