<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Bo3HeadToHeadService
{
    private const LIMIT = 5;

    /**
     * Fetch H2H and each team's recent form in the same bounded HTTP pool.
     * Reuse details and duplicate team queries only within this invocation;
     * nothing is read from or written to persistent cache.
     */
    public function enrich(array $matches): array
    {
        $missing = [];
        foreach ($matches as $index => $match) {
            if (! in_array($match['game'] ?? null, ['lol', 'valorant', 'cs', 'cs2'], true)) {
                $matches[$index]['h2h'] ??= null;

                continue;
            }
            $matches[$index]['h2h'] = null;
            $matches[$index]['recent_form'] = ['team1' => null, 'team2' => null];
            $detail = (array) ($match['bo3_detail'] ?? []) + $match;
            if ($this->positiveInt($detail['team1_id'] ?? null) !== null
                && $this->positiveInt($detail['team2_id'] ?? null) !== null
                && $this->positiveInt($detail['discipline_id'] ?? null) !== null) {
                continue;
            }
            $slug = $this->matchSlug((string) ($match['url'] ?? ''));
            if ($slug !== null && ! array_key_exists('bo3_detail', $match)) {
                $missing[$slug][] = $index;
            }
        }

        $details = $this->pool(array_map(fn (array $indexes, string $slug): array => [
            'path' => '/matches/'.rawurlencode($slug), 'query' => [],
        ], $missing, array_keys($missing)));
        foreach (array_values($missing) as $key => $indexes) {
            $response = $details[$key] ?? null;
            $detail = $response instanceof Response && $response->successful() ? $response->json() : null;
            foreach ($indexes as $index) {
                $matches[$index]['bo3_detail'] = is_array($detail) ? $detail : [];
            }
        }

        $requests = [];
        foreach ($matches as $index => $match) {
            if (! in_array($match['game'] ?? null, ['lol', 'valorant', 'cs', 'cs2'], true)) {
                continue;
            }
            $detail = (array) ($match['bo3_detail'] ?? []) + $match;
            $team1 = $this->positiveInt($detail['team1_id'] ?? null);
            $team2 = $this->positiveInt($detail['team2_id'] ?? null);
            $discipline = $this->positiveInt($detail['discipline_id'] ?? null);
            if ($discipline === null) {
                continue;
            }
            $cutoff = RecentForm::cutoff($match)->utc()->toIso8601String();
            $queries = [];
            if ($team1 !== null && $team2 !== null) {
                $queries['h2h'] = $team1.','.$team2;
            }
            foreach (['team1' => $team1, 'team2' => $team2] as $side => $team) {
                if ($team !== null) {
                    $queries[$side] = (string) $team;
                }
            }
            foreach ($queries as $kind => $ids) {
                $key = $discipline.':'.$ids.':'.$cutoff;
                $requests[$key] ??= [
                    'path' => '/matches',
                    'query' => [
                        'page' => ['offset' => 0, 'limit' => self::LIMIT],
                        'sort' => '-start_date',
                        'filter' => [
                            'matches.status' => ['in' => 'finished'],
                            'matches.team_ids' => ['contains' => $ids],
                            'matches.start_date' => ['lt' => $cutoff],
                            'matches.discipline_id' => ['eq' => $discipline],
                        ],
                    ],
                    'targets' => [],
                ];
                $requests[$key]['targets'][] = compact('index', 'kind', 'team1', 'team2', 'cutoff');
                if ($kind !== 'h2h') {
                    // Include opponent names with the same history request;
                    // no follow-up request is needed for individual results.
                    $requests[$key]['query']['with'] = 'teams';
                }
            }
        }

        $responses = $this->pool($requests);
        foreach ($requests as $key => $request) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['results'] ?? null)) {
                continue;
            }
            foreach ($request['targets'] as $target) {
                $cutoff = CarbonImmutable::parse($target['cutoff']);
                if ($target['kind'] === 'h2h') {
                    $matches[$target['index']]['h2h'] = $this->summarize($payload, $target['team1'], $target['team2'], $cutoff);
                } else {
                    $side = $target['kind'];
                    $matches[$target['index']]['recent_form'][$side] = $this->recentForm(array_values(array_filter($payload['results'], 'is_array')), $target[$side], $cutoff);
                }
            }
        }

        return $matches;
    }

    private function pool(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        try {
            return Http::pool(function (Pool $pool) use ($requests): void {
                foreach ($requests as $key => $request) {
                    $pool->as((string) $key)->acceptJson()->withUserAgent('AmandaBlogLineBot/1.0')
                        ->timeout((int) config('services.bo3.timeout_seconds', 10))
                        ->get($this->apiUrl().$request['path'], $request['query']);
                }
            }, 5);
        } catch (Throwable $exception) {
            $this->logWarning('bo3.gg match history unavailable.', ['type' => $exception::class]);

            return [];
        }
    }

    private function recentForm(array $rows, int $teamId, CarbonImmutable $cutoff): array
    {
        $results = [];
        $seen = [];
        usort($rows, fn (array $a, array $b): int => strcmp($b['start_date'] ?? '', $a['start_date'] ?? ''));
        foreach ($rows as $row) {
            if (! $this->completedBefore($row, $cutoff)
                || ! is_numeric($row['team1_score'] ?? null) || ! is_numeric($row['team2_score'] ?? null)) {
                continue;
            }
            $id = $row['id'] ?? $row['slug'] ?? json_encode([$row['team1_id'] ?? null, $row['team2_id'] ?? null, $row['start_date']]);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ((int) ($row['team1_id'] ?? 0) === $teamId) {
                [$own, $other] = [$row['team1_score'], $row['team2_score']];
                $opponent = $row['team2']['name'] ?? null;
            } elseif ((int) ($row['team2_id'] ?? 0) === $teamId) {
                [$own, $other] = [$row['team2_score'], $row['team1_score']];
                $opponent = $row['team1']['name'] ?? null;
            } else {
                continue;
            }
            $results[] = [
                'date' => $this->formatMatchDate($row['start_date']),
                'opponent' => is_string($opponent) && trim($opponent) !== '' ? trim($opponent) : '對手不明',
                'format' => is_numeric($row['bo_type'] ?? null) && (int) $row['bo_type'] > 0 ? 'BO'.(int) $row['bo_type'] : '—',
                'team_score' => (int) $own,
                'opponent_score' => (int) $other,
                'result' => $own > $other ? 'W' : ($own < $other ? 'L' : 'D'),
            ];
            if (count($results) === self::LIMIT) {
                break;
            }
        }

        return RecentForm::fromMatches($results);
    }

    private function completedBefore(array $row, CarbonImmutable $cutoff): bool
    {
        if (($row['status'] ?? 'finished') !== 'finished' || empty($row['start_date'])) {
            return false;
        }
        try {
            return CarbonImmutable::parse($row['end_date'] ?? $row['start_date'])->lt($cutoff);
        } catch (Throwable) {
            return false;
        }
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
    private function summarize(mixed $payload, int $team1Id, int $team2Id, CarbonImmutable $cutoff): ?array
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

        $rows = array_filter($payload['results'], 'is_array');
        usort($rows, fn (array $a, array $b): int => strcmp($b['start_date'] ?? '', $a['start_date'] ?? ''));
        foreach ($rows as $result) {
            if ($sampleSize >= self::LIMIT) {
                break;
            }
            if (! is_array($result)
                || ! $this->completedBefore($result, $cutoff)
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

            if ($score1 > $score2) {
                $team1Wins++;
                $winner = 'team1';
            } elseif ($score2 > $score1) {
                $team2Wins++;
                $winner = 'team2';
            } else {
                continue;
            }

            $sampleSize++;
            $team1Games += $score1;
            $team2Games += $score2;
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
                ->format('Y/m/d');
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
