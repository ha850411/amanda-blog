<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OddsApiLiveScoreService
{
    private const SUPPORTED_GAMES = ['lol', 'cs'];

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

                // Odds-API.io currently reports CS2 series/map wins in
                // scores.periods, not the live round score of the active map.
                // Do not keep presenting a possibly stale bo3.gg round score
                // after an Odds API event has been matched successfully.
                if (($matches[$index]['game'] ?? null) === 'cs') {
                    $matches[$index]['score'] = null;
                    unset($matches[$index]['score_source']);
                }

                if (! is_numeric($homeScore) || ! is_numeric($awayScore)) {
                    continue;
                }

                $team1IsHome = $this->matcher->firstTeamUsesHome(
                    $matches[$index],
                    (string) $event['home'],
                    (string) $event['away'],
                );
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

    private function score(mixed $left, mixed $right): string
    {
        return (int) $left.'：'.(int) $right;
    }
}
