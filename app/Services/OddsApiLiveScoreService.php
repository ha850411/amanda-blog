<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OddsApiLiveScoreService
{
    private const SUPPORTED_GAMES = ['lol', 'cs', 'valorant'];

    public function __construct(private readonly LiveMatchMatcher $matcher) {}

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        $apiKey = trim((string) config('services.odds.api_key'));

        if ($apiKey === '' || ! $this->containsSupportedMatch($matches)) {
            return $matches;
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent('AmandaBlogLineBot/1.0')
                ->withHeaders([
                    'Cache-Control' => 'no-cache, no-store',
                    'Pragma' => 'no-cache',
                ])
                ->timeout((int) config('services.odds.timeout_seconds', 10))
                ->get(rtrim((string) config('services.odds.base_url'), '/').'/events/live', [
                    'apiKey' => $apiKey,
                    'sport' => 'esports',
                ]);

            if (! $response->successful()) {
                Log::warning('Odds API live scores request failed.', ['status' => $response->status()]);

                return $matches;
            }

            $events = $response->json();
            $events = is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
            $matchedEvents = $this->matcher->match(
                $matches,
                $events,
                fn (array $event): array => [
                    'home' => is_string($event['home'] ?? null) ? $event['home'] : '',
                    'away' => is_string($event['away'] ?? null) ? $event['away'] : '',
                ],
                self::SUPPORTED_GAMES,
            );

            foreach ($matchedEvents as $index => $event) {
                $scores = is_array($event['scores'] ?? null) ? $event['scores'] : [];
                $homeScore = $scores['home'] ?? null;
                $awayScore = $scores['away'] ?? null;

                $matches[$index]['is_live'] = true;
                $matches[$index]['live_event_id'] = isset($event['id']) ? (string) $event['id'] : null;

                $team1IsHome = $this->matcher->firstTeamUsesHome(
                    $matches[$index],
                    (string) ($event['home'] ?? ''),
                    (string) ($event['away'] ?? ''),
                );

                $periodScore = $this->extractMapScoreFromPeriods($scores['periods'] ?? null, $team1IsHome);

                if ($periodScore !== null) {
                    $matches[$index]['score'] = $periodScore;
                    $matches[$index]['score_source'] = 'odds-api';
                }

                if (! is_numeric($homeScore) || ! is_numeric($awayScore)) {
                    continue;
                }

                $matches[$index]['series_score'] = $team1IsHome
                    ? $this->score($homeScore, $awayScore)
                    : $this->score($awayScore, $homeScore);
                $matches[$index]['series_score_source'] = 'odds-api';
                $matches[$index]['live_score_received_at'] = now('UTC')->toIso8601String();
            }
        } catch (Throwable $exception) {
            Log::warning('Odds API live scores connection failed.', ['type' => $exception::class]);
        }

        return $matches;
    }

    private function containsSupportedMatch(array $matches): bool
    {
        return collect($matches)->contains(
            fn (array $match): bool => in_array($match['game'] ?? null, self::SUPPORTED_GAMES, true),
        );
    }

    /**
     * Extract active map round score from Odds API periods data if available.
     */
    private function extractMapScoreFromPeriods(mixed $periods, bool $team1IsHome): ?string
    {
        if (! is_array($periods) || $periods === []) {
            return null;
        }

        $validPeriods = [];

        foreach ($periods as $key => $period) {
            if (! is_array($period)) {
                continue;
            }

            $home = $period['home'] ?? $period['score1'] ?? $period['team1'] ?? null;
            $away = $period['away'] ?? $period['score2'] ?? $period['team2'] ?? null;

            if (is_numeric($home) && is_numeric($away)) {
                $validPeriods[(string) $key] = [
                    'home' => (int) $home,
                    'away' => (int) $away,
                ];
            }
        }

        if ($validPeriods === []) {
            return null;
        }

        foreach (['current', 'live', 'current_map', 'active'] as $currentKey) {
            if (isset($validPeriods[$currentKey])) {
                $p = $validPeriods[$currentKey];

                return $team1IsHome ? $this->score($p['home'], $p['away']) : $this->score($p['away'], $p['home']);
            }
        }

        $allBinary = collect($validPeriods)->every(
            fn (array $p): bool => $p['home'] <= 1 && $p['away'] <= 1,
        );

        if ($allBinary) {
            if (count($validPeriods) > 1) {
                return null;
            }

            $first = reset($validPeriods);

            if ($first['home'] === 0 && $first['away'] === 0) {
                return $this->score(0, 0);
            }

            return null;
        }

        $lastPeriod = end($validPeriods);

        if ($lastPeriod !== false) {
            return $team1IsHome
                ? $this->score($lastPeriod['home'], $lastPeriod['away'])
                : $this->score($lastPeriod['away'], $lastPeriod['home']);
        }

        return null;
    }

    private function score(mixed $left, mixed $right): string
    {
        return (int) $left.'：'.(int) $right;
    }
}
