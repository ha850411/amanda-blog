<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SportsPlayerService
{
    public function register(array $match, array $stream): ?string
    {
        $player = ['event_id' => $match['id'], 'title' => $match['translated_title'] ?? '', 'stream' => $stream];
        if (! empty($match['start_at'])) {
            $player['event_expires_at'] = CarbonImmutable::parse($match['start_at'])->addSeconds(config('gks.event_lifetime_seconds', 21600))->toIso8601String();
        }
        if (! $this->mode($player)) {
            return null;
        }

        $token = hash_hmac('sha256', $match['id'].'|'.$stream['url'], config('app.key'));
        Cache::put('sports:player:'.$token, $player, config('sports_player.lifetime_seconds'));

        return $token;
    }

    public function find(string $token): ?array
    {
        $player = Cache::get('sports:player:'.$token);
        // Registration and lookup never fetch upstream. Media loads only on play.
        $mode = is_array($player) ? $this->mode($player) : null;
        if (! $mode) {
            return null;
        }

        return $player + ['mode' => $mode, 'token' => $token, 'media_adapter' => app(GksService::class)->descriptor($player) ? 'gks' : null];
    }

    private function mode(array $player): ?string
    {
        $url = $player['stream']['url'] ?? null;
        if (! is_string($url) || ! $this->safeEmbedUrl($url)) {
            return null;
        }
        if (app(SportsHlsService::class)->source($player)) {
            return 'hls';
        }

        return in_array($url, config('sports_player.verified_embed_urls', []), true) ? 'embed' : null;
    }

    private function safeEmbedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $hosts = array_merge(config('sports.embed_hosts'), config('streameast.embed_hosts'), [parse_url(config('mlbwebcast.base_url'), PHP_URL_HOST)]);

        return filter_var($url, FILTER_VALIDATE_URL) && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && in_array($parts['host'] ?? '', $hosts, true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }
}
