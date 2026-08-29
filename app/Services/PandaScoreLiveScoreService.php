<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PandaScoreLiveScoreService
{
    public function __construct(
        private readonly LiveMatchMatcher $matcher,
        private readonly PandaScoreFrameStream $frames,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        $token = trim((string) config('services.pandascore.api_token'));

        if ($token === '' || ! $this->containsLolMatch($matches)) {
            return $matches;
        }

        try {
            $runningMatches = $this->runningMatches($token);
            $matchedEvents = $this->matcher->match(
                $matches,
                $runningMatches,
                fn (array $event): array => $this->eventTeams($event),
            );

            foreach ($matchedEvents as $index => $event) {
                $url = $this->frameUrl($event);

                if (! is_string($url) || trim($url) === '') {
                    continue;
                }

                $matches[$index]['is_live'] = true;

                try {
                    $frame = $this->frames->firstFrame($url, $token);
                } catch (Throwable $exception) {
                    Log::warning('PandaScore live frame connection failed.', [
                        'match_id' => $event['match']['id'] ?? null,
                        'type' => $exception::class,
                    ]);

                    continue;
                }

                if ($frame === null) {
                    continue;
                }

                $matches[$index] = $this->applyFrame($matches[$index], $frame);
            }
        } catch (Throwable $exception) {
            Log::warning('PandaScore live scores connection failed.', ['type' => $exception::class]);
        }

        return $matches;
    }

    /** @return array<int, array<string, mixed>> */
    private function runningMatches(string $token): array
    {
        $response = Http::acceptJson()
            ->withToken($token)
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store',
                'Pragma' => 'no-cache',
            ])
            ->timeout((int) config('services.pandascore.timeout_seconds', 5))
            ->get(rtrim((string) config('services.pandascore.base_url'), '/').'/lives', [
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            Log::warning('PandaScore live endpoints request failed.', ['status' => $response->status()]);

            return [];
        }

        $payload = $response->json();

        if (is_array($payload['results'] ?? null)) {
            $payload = $payload['results'];
        } elseif (is_array($payload['data'] ?? null)) {
            $payload = $payload['data'];
        }

        return is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /** @return array{home: string, away: string} */
    private function eventTeams(array $event): array
    {
        $match = is_array($event['match'] ?? null) ? $event['match'] : $event;
        $opponents = is_array($match['opponents'] ?? null) ? array_values($match['opponents']) : [];

        return [
            'home' => is_string($opponents[0]['opponent']['name'] ?? null)
                ? $opponents[0]['opponent']['name']
                : '',
            'away' => is_string($opponents[1]['opponent']['name'] ?? null)
                ? $opponents[1]['opponent']['name']
                : '',
        ];
    }

    private function frameUrl(array $event): ?string
    {
        $endpoints = is_array($event['endpoints'] ?? null) ? $event['endpoints'] : [];

        foreach ($endpoints as $endpoint) {
            if (is_array($endpoint)
                && mb_strtolower((string) ($endpoint['type'] ?? '')) === 'frames'
                && ($endpoint['open'] ?? true)
                && is_string($endpoint['url'] ?? null)) {
                return $endpoint['url'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $frame */
    private function applyFrame(array $match, array $frame): array
    {
        $blue = $frame['blue'];
        $red = $frame['red'];
        $team1IsBlue = $this->matcher->firstTeamUsesHome(
            $match,
            (string) ($blue['name'] ?? ''),
            (string) ($red['name'] ?? ''),
        );
        $team1 = $team1IsBlue ? $blue : $red;
        $team2 = $team1IsBlue ? $red : $blue;

        if (is_numeric($team1['kills'] ?? null) && is_numeric($team2['kills'] ?? null)) {
            $match['score'] = $this->score($team1['kills'], $team2['kills']);
            $match['score_source'] = 'pandascore';
        }

        if (($match['series_score_source'] ?? null) !== 'odds-api'
            && is_numeric($team1['score'] ?? null)
            && is_numeric($team2['score'] ?? null)) {
            $match['series_score'] = $this->score($team1['score'], $team2['score']);
            $match['series_score_source'] = 'pandascore';
        }

        $match['is_live'] = true;
        $match['live_game_id'] = isset($frame['game']['id']) ? (string) $frame['game']['id'] : null;
        $match['live_score_received_at'] = now('UTC')->toIso8601String();

        return $match;
    }

    private function containsLolMatch(array $matches): bool
    {
        return collect($matches)->contains(fn (array $match): bool => ($match['game'] ?? null) === 'lol');
    }

    private function score(mixed $left, mixed $right): string
    {
        return (int) $left.'：'.(int) $right;
    }
}
