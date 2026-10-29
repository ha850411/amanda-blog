<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NbaScheduleService
{
    /** @return array<int, array<string, mixed>> */
    public function forRange(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $teamIds = array_values(array_unique(array_map('intval', array_filter(
            (array) config('nba.team_ids', []),
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        ))));
        if ($teamIds === []) {
            return [];
        }

        // ESPN's NBA scoreboard accepts one US calendar day per request.
        $requests = [];
        $first = $startDate->startOfDay()->setTimezone('America/New_York')->startOfDay();
        $last = $endDate->endOfDay()->setTimezone('America/New_York')->startOfDay();
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $requests[$day->format('Ymd')] = ['path' => '/scoreboard', 'query' => ['dates' => $day->format('Ymd'), 'limit' => 100]];
        }

        $matches = [];
        foreach ($this->fetch($requests) as $games) {
            if ($games === null) {
                throw new RuntimeException('NBA schedule unavailable.');
            }
            foreach ($games as $game) {
                if (! in_array($game['team1_id'], $teamIds, true) && ! in_array($game['team2_id'], $teamIds, true)) {
                    continue;
                }
                $game['start_at'] = $game['start_at']->setTimezone($startDate->timezone);
                if ($game['start_at']->betweenIncluded($startDate->startOfDay(), $endDate->endOfDay())) {
                    $matches[$game['event_id']] = $game;
                }
            }
        }
        usort($matches, fn (array $a, array $b): int => $a['start_at'] <=> $b['start_at']);

        return array_values($matches);
    }

    /** Fetch each visible team's history once per season, including untracked opponents. */
    public function enrichRecentForm(array $matches): array
    {
        $targets = [];
        foreach ($matches as $index => $match) {
            if (($match['game'] ?? null) !== 'nba') {
                continue;
            }
            $matches[$index]['recent_form'] = ['team1' => null, 'team2' => null];
            $cutoff = RecentForm::cutoff($match);
            $season = $cutoff->year + ($cutoff->month >= 9 ? 1 : 0);
            foreach (['team1', 'team2'] as $side) {
                $id = (int) ($match[$side.'_id'] ?? 0);
                if ($id > 0) {
                    $targets[] = compact('index', 'side', 'id', 'cutoff', 'season');
                }
            }
        }
        if ($targets === []) {
            return $matches;
        }

        $history = $this->fetchHistory($targets);
        $missing = array_filter($targets, fn (array $target): bool => isset($history[$target['id'].':'.$target['season']])
            && count($this->recentMatches($history[$target['id'].':'.$target['season']], $target)) < 5);
        // At the start of a season, fill short samples from the previous season.
        $previous = $this->fetchHistory(array_map(fn (array $target): array => array_replace($target, ['season' => $target['season'] - 1]), $missing));
        foreach ($targets as $target) {
            $games = $history[$target['id'].':'.$target['season']] ?? null;
            if ($games === null) {
                continue;
            }
            $games = array_merge($games, $previous[$target['id'].':'.($target['season'] - 1)] ?? []);
            $matches[$target['index']]['recent_form'][$target['side']] = RecentForm::fromMatches($this->recentMatches($games, $target));
        }

        return $matches;
    }

    public function filteredUrl(CarbonImmutable $date): string
    {
        return 'https://www.espn.com/nba/scoreboard/_/date/'.$date->setTimezone('America/New_York')->format('Ymd');
    }

    /** @return array<string, array|null> */
    private function fetchHistory(array $targets): array
    {
        $requests = [];
        $keys = [];
        foreach ($targets as $target) {
            $key = $target['id'].':'.$target['season'];
            $keys[$key] = true;
            // Regular season and playoffs are later than preseason. Fetch
            // those together and only load preseason for short samples.
            foreach ([2, 3] as $type) {
                $requests[$key.':'.$type] = [
                    'path' => '/teams/'.$target['id'].'/schedule',
                    'query' => ['season' => $target['season'], 'seasontype' => $type],
                ];
            }
        }
        $responses = $this->fetch($requests);
        $history = [];
        foreach (array_keys($keys) as $key) {
            $history[$key] = [];
            foreach ([2, 3] as $type) {
                if (($responses[$key.':'.$type] ?? null) === null) {
                    $history[$key] = null;
                    break;
                }
                $history[$key] = array_merge($history[$key], $responses[$key.':'.$type]);
            }
        }

        $preseasonRequests = [];
        foreach ($targets as $target) {
            $key = $target['id'].':'.$target['season'];
            if ($history[$key] !== null && count($this->recentMatches($history[$key], $target)) < 5) {
                $preseasonRequests[$key] = [
                    'path' => '/teams/'.$target['id'].'/schedule',
                    'query' => ['season' => $target['season'], 'seasontype' => 1],
                ];
            }
        }
        foreach ($this->fetch($preseasonRequests) as $key => $games) {
            $history[$key] = $games === null ? null : array_merge($history[$key], $games);
        }

        return $history;
    }

    /** @return array<string, array|null> Null distinguishes a failed feed from an empty schedule. */
    private function fetch(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        try {
            $responses = ScheduleHttp::pool(function (Pool $pool) use ($requests): void {
                foreach ($requests as $key => $request) {
                    $pool->as((string) $key)->acceptJson()->withUserAgent('Mozilla/5.0')
                        ->connectTimeout(3)->timeout((int) config('nba.timeout_seconds', 10))
                        ->get(rtrim((string) config('nba.api_url'), '/').$request['path'], $request['query']);
                }
            }, concurrency: 5);
        } catch (Throwable $exception) {
            Log::warning('NBA feed unavailable.', ['type' => $exception::class]);
            $responses = [];
        }
        $results = [];
        foreach ($requests as $key => $request) {
            try {
                $response = $responses[$key] ?? null;
                $events = $response instanceof Response && $response->successful() ? $response->json('events') : null;
                if (! is_array($events) || ! array_is_list($events)) {
                    throw new RuntimeException('NBA feed response is invalid.');
                }
                $games = [];
                foreach ($events as $event) {
                    $game = is_array($event) ? $this->normalize($event) : null;
                    if ($game !== null) {
                        $games[$game['event_id']] = $game;
                    }
                }
                if ($events !== [] && $games === []) {
                    throw new RuntimeException('NBA events are invalid.');
                }
                $results[$key] = array_values($games);
            } catch (Throwable $exception) {
                Log::warning('NBA feed unavailable.', ['request' => $key, 'type' => $exception::class]);
                $results[$key] = null;
            }
        }

        return $results;
    }

    private function normalize(array $event): ?array
    {
        $competition = $event['competitions'][0] ?? [];
        $teams = [];
        foreach ($competition['competitors'] ?? [] as $team) {
            $teams[$team['homeAway'] ?? ''] = $team;
        }
        $away = $teams['away'] ?? [];
        $home = $teams['home'] ?? [];
        if (empty($event['id']) || empty($event['date']) || empty($away['team']['id']) || empty($home['team']['id'])) {
            return null;
        }
        try {
            $start = CarbonImmutable::parse($event['date'])->setTimezone((string) config('services.bo3.timezone', 'Asia/Taipei'));
        } catch (Throwable) {
            return null;
        }
        $status = $competition['status']['type'] ?? $event['status']['type'] ?? [];
        $state = $status['state'] ?? '';
        $name = $status['name'] ?? '';
        $cancelled = in_array($name, ['STATUS_POSTPONED', 'STATUS_CANCELED', 'STATUS_CANCELLED'], true);
        $finished = ! $cancelled && ($status['completed'] ?? false) && $state === 'post';
        $live = ! $cancelled && $state === 'in';
        $awayScore = $this->score($away);
        $homeScore = $this->score($home);
        $team1 = $this->teamName($away['team']);
        $team2 = $this->teamName($home['team']);

        return [
            'game' => 'nba',
            'event_id' => (string) $event['id'],
            'name' => $team1.' vs '.$team2.' '.($event['name'] ?? '').' '.($event['shortName'] ?? ''),
            'team1' => $team1,
            'team2' => $team2,
            // Keep provider names for odds matching while displaying Chinese labels.
            'team1_name' => $away['team']['displayName'] ?? $away['team']['name'] ?? '',
            'team2_name' => $home['team']['displayName'] ?? $home['team']['name'] ?? '',
            'team1_id' => (int) $away['team']['id'],
            'team2_id' => (int) $home['team']['id'],
            'tournament' => 'NBA・'.($competition['venue']['fullName'] ?? '美國職籃'),
            'format' => '單場',
            'start_at' => $start,
            'time_tbd' => ($competition['timeValid'] ?? $event['timeValid'] ?? true) === false || $name === 'STATUS_SCHEDULED_TBD',
            'is_live' => $live,
            'is_finished' => $finished,
            'is_cancelled' => $cancelled,
            'status_label' => match ($name) {
                'STATUS_POSTPONED' => '延期',
                'STATUS_CANCELED', 'STATUS_CANCELLED' => '取消',
                'STATUS_DELAYED' => '延遲',
                'STATUS_SUSPENDED' => '暫停',
                'STATUS_HALFTIME' => '中場休息',
                default => $finished ? '已結束' : ($live ? '進行中' : '未開打'),
            },
            'series_score' => null,
            'score' => ($live || $finished) && $awayScore !== null && $homeScore !== null ? $awayScore.'：'.$homeScore : null,
            'odds' => null,
            'h2h' => null,
            'url' => 'https://www.espn.com/nba/game/_/gameId/'.rawurlencode((string) $event['id']),
        ];
    }

    private function recentMatches(array $games, array $target): array
    {
        $completed = [];
        foreach ($games as $game) {
            if (! $game['is_finished'] || $game['score'] === null || $game['start_at']->gte($target['cutoff'])
                || $game['start_at']->lt($target['cutoff']->subDays(400))) {
                continue;
            }
            $away = $game['team1_id'] === $target['id'];
            if (! $away && $game['team2_id'] !== $target['id']) {
                continue;
            }
            [$awayScore, $homeScore] = array_map('intval', explode('：', $game['score']));
            [$own, $opponent] = $away ? [$awayScore, $homeScore] : [$homeScore, $awayScore];
            $completed[$game['event_id']] = ['date' => $game['start_at'], 'match' => [
                'date' => $game['start_at']->format('Y/m/d'),
                'opponent' => $game[$away ? 'team2' : 'team1'],
                'format' => '單場',
                'team_score' => $own,
                'opponent_score' => $opponent,
                'result' => $own > $opponent ? 'W' : ($own < $opponent ? 'L' : 'D'),
            ]];
        }
        usort($completed, fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return array_slice(array_column($completed, 'match'), 0, 5);
    }

    private function score(array $team): ?int
    {
        $score = $team['score'] ?? null;
        $score = is_array($score) ? ($score['value'] ?? $score['displayValue'] ?? null) : $score;

        return is_numeric($score) ? (int) $score : null;
    }

    private function teamName(array $team): string
    {
        return (string) (config('nba.team_names', [])[$team['id'] ?? 0] ?? $team['displayName'] ?? $team['name'] ?? '待定');
    }
}
