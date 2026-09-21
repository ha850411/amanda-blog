<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MlbScheduleService
{
    /** @return array<int, array<string, mixed>> */
    public function forRange(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $teamIds = array_values(array_unique(array_map('intval', array_filter(
            (array) config('mlb.team_ids', []),
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        ))));
        if ($teamIds === []) {
            return [];
        }

        // MLB dates are US calendar dates. Fetch a padded range, then use the
        // actual start instant to select the requested Taiwan calendar days.
        $games = $this->fetch($teamIds, $startDate->subDay(), $endDate->addDay());
        $matches = [];
        foreach ($games as $game) {
            $away = $game['teams']['away'] ?? [];
            $home = $game['teams']['home'] ?? [];
            if (! in_array($away['team']['id'] ?? null, $teamIds, true)
                && ! in_array($home['team']['id'] ?? null, $teamIds, true)) {
                continue;
            }
            if (empty($game['gameDate']) || empty($game['gamePk'])) {
                continue;
            }
            $start = CarbonImmutable::parse($game['gameDate'])->setTimezone($startDate->timezone);
            if ($start->lt($startDate->startOfDay()) || $start->gt($endDate->endOfDay())) {
                continue;
            }

            $status = $game['status'] ?? [];
            $state = $status['abstractGameState'] ?? '';
            $detail = $status['detailedState'] ?? '';
            $team1 = $this->teamName($away['team'] ?? []);
            $team2 = $this->teamName($home['team'] ?? []);
            $score = in_array($state, ['Live', 'Final'], true) && isset($away['score'], $home['score'])
                ? $away['score'].'：'.$home['score'] : null;
            $matches[(string) $game['gamePk']] = [
                'game' => 'mlb',
                'game_pk' => $game['gamePk'],
                'name' => $team1.' vs '.$team2.' '.($away['team']['name'] ?? '').' '.($home['team']['name'] ?? ''),
                'team1' => $team1,
                'team2' => $team2,
                'team1_id' => $away['team']['id'] ?? null,
                'team2_id' => $home['team']['id'] ?? null,
                'tournament' => 'MLB・'.($game['venue']['name'] ?? '美國職棒'),
                'format' => '單場',
                'start_at' => $start,
                'time_tbd' => (bool) ($status['startTimeTBD'] ?? false),
                'is_live' => $state === 'Live',
                'is_finished' => $state === 'Final',
                'is_cancelled' => in_array($detail, ['Postponed', 'Cancelled'], true),
                'status_label' => match ($detail) {
                    'Final', 'Game Over', 'Completed Early' => '已結束',
                    'Postponed' => '延期', 'Cancelled' => '取消',
                    'Delayed', 'Delayed Start' => '延遲', 'Suspended' => '暫停',
                    'In Progress', 'Manager challenge', 'Umpire review' => '進行中',
                    'Scheduled', 'Pre-Game', 'Warmup' => '未開打',
                    default => $detail,
                },
                'series_score' => null,
                'score' => $score,
                'odds' => null,
                'h2h' => null,
                'url' => 'https://www.mlb.com/gameday/'.$game['gamePk'],
            ];
        }

        return array_values($matches);
    }

    /** Fetch all visible teams (including opponents) together, without caching. */
    public function enrichRecentForm(array $matches): array
    {
        $requests = [];
        foreach ($matches as $index => $match) {
            if (($match['game'] ?? null) !== 'mlb') {
                continue;
            }
            $matches[$index]['recent_form'] = ['team1' => null, 'team2' => null];
            foreach (['team1', 'team2'] as $side) {
                $id = (int) ($match[$side.'_id'] ?? 0);
                if ($id > 0) {
                    $requests[] = ['index' => $index, 'side' => $side, 'id' => $id, 'cutoff' => RecentForm::cutoff($match)];
                }
            }
        }
        if ($requests === []) {
            return $matches;
        }

        $earliest = min(array_column($requests, 'cutoff'));
        $latest = max(array_column($requests, 'cutoff'));
        $ids = array_values(array_unique(array_column($requests, 'id')));
        try {
            $games = $this->fetch($ids, $earliest->subDays(30), $latest);
            // Only extend the window for teams that lack five completed games
            // (for example at the start of a season); normal days need one call.
            $missing = array_values(array_unique(array_column(array_filter(
                $requests,
                fn (array $request): bool => count($this->recentMatches($games, $request)) < 5,
            ), 'id')));
            if ($missing !== []) {
                try {
                    $games = array_merge($games, $this->fetch($missing, $earliest->subDays(400), $earliest->subDays(31)));
                } catch (Throwable $exception) {
                    Log::warning('MLB extended history unavailable.', ['type' => $exception::class]);
                }
            }
            foreach ($requests as $request) {
                $matches[$request['index']]['recent_form'][$request['side']] = RecentForm::fromMatches($this->recentMatches($games, $request));
            }
        } catch (Throwable $exception) {
            Log::warning('MLB recent form unavailable.', ['type' => $exception::class]);
        }

        return $matches;
    }

    public function filteredUrl(CarbonImmutable $date): string
    {
        // The MLB website uses US dates; link to the date covering Taiwan mornings.
        return 'https://www.mlb.com/schedule/'.$date->setTimezone('America/New_York')->format('Y-m-d');
    }

    private function fetch(array $teamIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = [
            'sportId' => 1,
            'teamId' => implode(',', $teamIds),
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ];
        // Scores and team names are already in the schedule response; avoid
        // full linescores, player rosters and an API request for every game.
        $query['fields'] = 'dates,games,gamePk,gameDate,gameNumber,status,abstractGameState,detailedState,startTimeTBD,teams,away,home,team,id,name,score,isWinner,venue,resumeDate';
        $payload = Http::acceptJson()->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('mlb.timeout_seconds', 10))
            ->get(rtrim((string) config('mlb.api_url'), '/').'/schedule', $query)
            ->throw()->json();
        if (! is_array($payload) || ! is_array($payload['dates'] ?? null)) {
            throw new RuntimeException('MLB schedule response is invalid.');
        }

        return collect($payload['dates'])->flatMap(fn (array $day): array => $day['games'] ?? [])->unique('gamePk')->values()->all();
    }

    private function recentMatches(array $games, array $request): array
    {
        $completed = [];
        foreach ($games as $game) {
            if (($game['status']['abstractGameState'] ?? null) !== 'Final' || empty($game['gameDate'])) {
                continue;
            }
            // A suspended game is only usable after its resumption, not on its original date.
            $date = CarbonImmutable::parse($game['resumeDate'] ?? $game['gameDate']);
            if ($date->gte($request['cutoff'])) {
                continue;
            }
            $away = $game['teams']['away'] ?? [];
            $home = $game['teams']['home'] ?? [];
            $isAway = ($away['team']['id'] ?? null) === $request['id'];
            if (! $isAway && ($home['team']['id'] ?? null) !== $request['id']) {
                continue;
            }
            if (! is_numeric($away['score'] ?? null) || ! is_numeric($home['score'] ?? null)) {
                continue;
            }
            $own = $isAway ? $away['score'] : $home['score'];
            $opponent = $isAway ? $home['score'] : $away['score'];
            $completed[(string) $game['gamePk']] = [
                'date' => $date,
                'number' => $game['gameNumber'] ?? 1,
                'match' => [
                    'date' => $date->setTimezone((string) config('services.bo3.timezone', 'Asia/Taipei'))->format('Y/m/d'),
                    'opponent' => $this->teamName(($isAway ? $home : $away)['team'] ?? []),
                    'format' => '單場',
                    'team_score' => (int) $own,
                    'opponent_score' => (int) $opponent,
                    'result' => $own > $opponent ? 'W' : ($own < $opponent ? 'L' : 'D'),
                ],
            ];
        }
        usort($completed, fn (array $a, array $b): int => ($b['date'] <=> $a['date']) ?: ($b['number'] <=> $a['number']));

        return array_slice(array_column($completed, 'match'), 0, 5);
    }

    private function teamName(array $team): string
    {
        return (string) (config('mlb.team_names', [])[$team['id'] ?? 0] ?? $team['name'] ?? '待定');
    }
}
