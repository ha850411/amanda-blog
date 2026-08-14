<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Bo3HeadToHeadService
{
    private const LIMIT = 5;

    /**
     * Add bo3.gg's latest head-to-head summary to visible LoL matches.
     * A missing or unavailable H2H response is optional and never removes a match.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        $slugs = [];

        foreach ($matches as $index => $match) {
            $matches[$index]['h2h'] = null;

            if (($match['game'] ?? null) !== 'lol') {
                continue;
            }

            $slug = $this->matchSlug((string) ($match['url'] ?? ''));

            if ($slug !== null) {
                $slugs[$index] = $slug;
            }
        }

        if ($slugs === []) {
            return $matches;
        }

        try {
            $details = $this->matchDetails(array_values(array_unique($slugs)));
        } catch (Throwable $exception) {
            $this->logWarning('bo3.gg match detail connection for head-to-head failed.', [
                'type' => $exception::class,
            ]);

            return $matches;
        }
        $requests = [];

        foreach ($slugs as $index => $slug) {
            $detail = $details[$slug] ?? [];
            $team1Id = $this->positiveInt($detail['team1_id'] ?? null);
            $team2Id = $this->positiveInt($detail['team2_id'] ?? null);
            $disciplineId = $this->positiveInt($detail['discipline_id'] ?? null);

            if ($team1Id === null || $team2Id === null || $disciplineId === null) {
                continue;
            }

            $cutoff = CarbonImmutable::now((string) config('services.bo3.timezone', 'Asia/Taipei'))
                ->format('Y-m-d');
            // Keep the scheduled team order in the key because the summary is
            // expressed as team1 versus team2, even when a past row is reversed.
            $cacheKey = sprintf('bo3-h2h:v2:%d:%d-%d:%s', $disciplineId, $team1Id, $team2Id, $cutoff);

            try {
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    $matches[$index]['h2h'] = $cached !== [] ? $cached : null;

                    continue;
                }
            } catch (Throwable) {
                // Cache is optional; continue with the API request.
            }

            $requests[$cacheKey] = [
                'indexes' => [...($requests[$cacheKey]['indexes'] ?? []), $index],
                'team1_id' => $team1Id,
                'team2_id' => $team2Id,
                'discipline_id' => $disciplineId,
                'cutoff' => $cutoff,
            ];
        }

        if ($requests === []) {
            return $matches;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($requests): void {
                foreach ($requests as $cacheKey => $request) {
                    $pool->as($cacheKey)
                        ->acceptJson()
                        ->withUserAgent('AmandaBlogLineBot/1.0')
                        ->timeout((int) config('services.bo3.timeout_seconds', 10))
                        ->get($this->apiUrl().'/matches', $this->query($request));
                }
            }, 5);
        } catch (Throwable $exception) {
            $this->logWarning('bo3.gg head-to-head connection failed.', [
                'type' => $exception::class,
            ]);

            return $matches;
        }

        foreach ($requests as $cacheKey => $request) {
            $response = $responses[$cacheKey] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                $this->logWarning('bo3.gg head-to-head request failed.', [
                    'team1_id' => $request['team1_id'],
                    'team2_id' => $request['team2_id'],
                    'status' => $response instanceof Response ? $response->status() : null,
                ]);

                continue;
            }

            $summary = $this->summarize(
                $response->json(),
                $request['team1_id'],
                $request['team2_id'],
            );

            try {
                Cache::put(
                    $cacheKey,
                    $summary ?? [],
                    (int) config('services.bo3.h2h_cache_seconds', 300),
                );
            } catch (Throwable) {
                // Cache is optional for this enrichment.
            }

            foreach ($request['indexes'] as $index) {
                $matches[$index]['h2h'] = $summary;
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<string, array<string, mixed>>
     */
    private function matchDetails(array $slugs): array
    {
        $details = [];
        $missing = [];

        foreach ($slugs as $slug) {
            $cacheKey = 'bo3-h2h:detail:'.$slug;

            try {
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    $details[$slug] = $cached;

                    continue;
                }
            } catch (Throwable) {
                // Cache is optional; continue with the API request.
            }

            $missing[] = $slug;
        }

        if ($missing === []) {
            return $details;
        }

        $responses = Http::pool(function (Pool $pool) use ($missing): void {
            foreach ($missing as $slug) {
                $pool->as($slug)
                    ->acceptJson()
                    ->withUserAgent('AmandaBlogLineBot/1.0')
                    ->timeout((int) config('services.bo3.timeout_seconds', 10))
                    ->get($this->apiUrl().'/matches/'.rawurlencode($slug));
            }
        }, 5);

        foreach ($missing as $slug) {
            $response = $responses[$slug] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                $this->logWarning('bo3.gg match detail request for head-to-head failed.', [
                    'slug' => $slug,
                    'status' => $response instanceof Response ? $response->status() : null,
                ]);

                continue;
            }

            $detail = $response->json();
            $detail = is_array($detail) ? $detail : [];
            $details[$slug] = $detail;

            try {
                Cache::put(
                    'bo3-h2h:detail:'.$slug,
                    $detail,
                    (int) config('services.bo3.h2h_cache_seconds', 300),
                );
            } catch (Throwable) {
                // Cache is optional for this enrichment.
            }
        }

        return $details;
    }

    /** @param array{team1_id: int, team2_id: int, discipline_id: int, cutoff: string} $request */
    private function query(array $request): array
    {
        return [
            'page' => [
                'offset' => 0,
                'limit' => self::LIMIT,
            ],
            'sort' => '-start_date',
            'filter' => [
                'matches.status' => ['in' => 'finished'],
                'matches.team_ids' => ['contains' => $request['team1_id'].','.$request['team2_id']],
                'matches.start_date' => ['lt' => $request['cutoff']],
                'matches.discipline_id' => ['eq' => $request['discipline_id']],
            ],
        ];
    }

    /**
     * @return array{
     *     sample_size: int,
     *     history_total: int,
     *     team1_wins: int,
     *     team2_wins: int,
     *     team1_games: int,
     *     team2_games: int,
     *     series: array<int, array{date: string, format: string, team1_score: int, team2_score: int, winner: 'team1'|'team2'}>
     * }|null
     */
    private function summarize(mixed $payload, int $team1Id, int $team2Id): ?array
    {
        if (! is_array($payload) || ! is_array($payload['results'] ?? null)) {
            return null;
        }

        $sampleSize = 0;
        $team1Wins = 0;
        $team2Wins = 0;
        $team1Games = 0;
        $team2Games = 0;
        $series = [];

        foreach ($payload['results'] as $result) {
            if (! is_array($result)
                || ! is_numeric($result['team1_id'] ?? null)
                || ! is_numeric($result['team2_id'] ?? null)
                || ! is_numeric($result['team1_score'] ?? null)
                || ! is_numeric($result['team2_score'] ?? null)) {
                continue;
            }

            $rowTeam1Id = (int) $result['team1_id'];
            $rowTeam2Id = (int) $result['team2_id'];
            $rowTeam1Score = (int) $result['team1_score'];
            $rowTeam2Score = (int) $result['team2_score'];

            if ($rowTeam1Id === $team1Id && $rowTeam2Id === $team2Id) {
                $score1 = $rowTeam1Score;
                $score2 = $rowTeam2Score;
            } elseif ($rowTeam1Id === $team2Id && $rowTeam2Id === $team1Id) {
                $score1 = $rowTeam2Score;
                $score2 = $rowTeam1Score;
            } else {
                continue;
            }

            $sampleSize++;
            $team1Games += $score1;
            $team2Games += $score2;

            if ($score1 > $score2) {
                $team1Wins++;
                $winner = 'team1';
            } elseif ($score2 > $score1) {
                $team2Wins++;
                $winner = 'team2';
            } else {
                continue;
            }

            $series[] = [
                'date' => $this->formatMatchDate($result['start_date'] ?? null),
                'format' => $this->formatBestOf($result['bo_type'] ?? null, $score1, $score2),
                'team1_score' => $score1,
                'team2_score' => $score2,
                'winner' => $winner,
            ];
        }

        if ($sampleSize === 0) {
            return null;
        }

        $historyTotal = $payload['total']['count'] ?? $sampleSize;

        return [
            'sample_size' => $sampleSize,
            'history_total' => is_numeric($historyTotal) ? (int) $historyTotal : $sampleSize,
            'team1_wins' => $team1Wins,
            'team2_wins' => $team2Wins,
            'team1_games' => $team1Games,
            'team2_games' => $team2Games,
            'series' => $series,
        ];
    }

    private function formatMatchDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '日期不明';
        }

        try {
            return CarbonImmutable::parse($value)
                ->setTimezone((string) config('services.bo3.timezone', 'Asia/Taipei'))
                ->format('m/d');
        } catch (Throwable) {
            return '日期不明';
        }
    }

    private function formatBestOf(mixed $value, int $team1Score, int $team2Score): string
    {
        if (is_numeric($value) && (int) $value > 0) {
            return 'BO'.(int) $value;
        }

        $winningScore = max($team1Score, $team2Score);

        return $winningScore > 0 ? 'BO'.(($winningScore * 2) - 1) : 'BO?';
    }

    private function matchSlug(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! preg_match('~/matches/([^/]+)$~', rtrim($path, '/'), $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.bo3.api_url', 'https://api.bo3.gg/api/v1'), '/');
    }

    /** @param array<string, mixed> $context */
    private function logWarning(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Logging must not turn optional H2H enrichment into a failed reply.
        }
    }
}
