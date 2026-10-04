<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class SportsPlayerService
{
    public function register(array $match, array $stream): ?string
    {
        if (! $this->supports($stream['url'] ?? null)) {
            return null;
        }

        $token = hash_hmac('sha256', $match['id'].'|'.$stream['url'], config('app.key'));
        Cache::put('sports:player:'.$token, [
            'event_id' => $match['id'], 'title' => $match['translated_title'],
            'stream' => $stream,
        ], config('sports_player.lifetime_seconds'));

        return $token;
    }

    public function find(string $token): ?array
    {
        $player = Cache::get('sports:player:'.$token);
        $url = $player['stream']['url'] ?? null;

        // Revalidate cached links too. Only the client loads the external player;
        // this service must never fetch playlists, segments, or player scripts.
        if (! is_array($player) || ! $this->supports($url)) {
            return null;
        }

        return $player;
    }

    private function supports(mixed $url): bool
    {
        // A safe URL or a successful iframe load does not prove playback works.
        // Only exact sources verified with our current sandbox can be offered.
        return is_string($url) && $this->safeEmbedUrl($url)
            && in_array($url, config('sports_player.verified_embed_urls', []), true);
    }

    private function safeEmbedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $hosts = array_merge(config('sports.embed_hosts'), config('streameast.embed_hosts'));

        return filter_var($url, FILTER_VALIDATE_URL) && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && in_array($parts['host'] ?? '', $hosts, true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }
}
