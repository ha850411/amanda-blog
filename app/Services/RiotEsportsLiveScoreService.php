<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RiotEsportsLiveScoreService
{
    private const EVENT_MARKER = '{"__typename":"EventMatch"';

    public function __construct(private readonly LiveMatchMatcher $matcher) {}

    /**
     * Riot's LoL Esports website and live-stat feed are requested directly on
     * every invocation. This service deliberately does not use Laravel Cache.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        if (! $this->containsLiveLolMatch($matches)) {
            return $matches;
        }

        try {
            $events = $this->liveEvents();
            $matchedEvents = $this->matcher->match(
                $matches,
                $events,
                fn (array $event): array => $this->eventTeams($event),
            );

            foreach ($matchedEvents as $index => $event) {
                if (($event['match']['state'] ?? null) === 'completed') {
                    $matches[$index] = $this->applyCompletedEvent($matches[$index], $event);

                    continue;
                }

                $game = $this->currentGame($event);

                if ($game === null || ! is_string($game['id'] ?? null)) {
                    continue;
                }

                $window = $this->latestWindow($game['id']);

                if ($window === null) {
                    continue;
                }

                $matches[$index] = $this->applyWindow($matches[$index], $event, $game, $window);
            }
        } catch (Throwable $exception) {
            Log::warning('Riot LoL Esports live scores connection failed.', [
                'type' => $exception::class,
            ]);
        }

        return $matches;
    }

    /** @return array<int, array<string, mixed>> */
    private function liveEvents(): array
    {
        $response = Http::accept('text/html,application/xhtml+xml')
            ->withUserAgent('Mozilla/5.0 (compatible; AmandaBlogLineBot/1.0)')
            ->withHeaders($this->freshHeaders())
            ->timeout((int) config('services.riot_esports.timeout_seconds', 8))
            ->get((string) config('services.riot_esports.schedule_url'));

        if (! $response->successful()) {
            Log::warning('Riot LoL Esports schedule request failed.', [
                'status' => $response->status(),
            ]);

            return [];
        }

        return $this->extractLiveEvents($response->body());
    }

    /** @return array<int, array<string, mixed>> */
    private function extractLiveEvents(string $html): array
    {
        $events = [];
        $offset = 0;

        while (($start = strpos($html, self::EVENT_MARKER, $offset)) !== false) {
            $json = $this->jsonObjectAt($html, $start);

            if ($json === null) {
                $offset = $start + strlen(self::EVENT_MARKER);

                continue;
            }

            $offset = $start + strlen($json);
            $event = json_decode($json, true);

            if (! is_array($event) || ! is_array($event['match'] ?? null)) {
                continue;
            }

            $matchState = $event['match']['state'] ?? null;
            $hasCurrentGame = collect($event['match']['games'] ?? [])->contains(
                fn (mixed $game): bool => is_array($game)
                    && ($game['state'] ?? null) === 'inProgress',
            );

            if ($matchState !== 'completed' && ! $hasCurrentGame) {
                continue;
            }

            $event['date'] = $event['startTime'] ?? null;
            $events[(string) ($event['id'] ?? count($events))] = $event;
        }

        return array_values($events);
    }

    private function jsonObjectAt(string $input, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($input);

        for ($index = $start; $index < $length; $index++) {
            $character = $input[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth === 0) {
                return substr($input, $start, $index - $start + 1);
            }
        }

        return null;
    }

    /** @return array{home: string, away: string} */
    private function eventTeams(array $event): array
    {
        $teams = is_array($event['matchTeams'] ?? null) ? array_values($event['matchTeams']) : [];

        return [
            'home' => is_string($teams[0]['name'] ?? null) ? $teams[0]['name'] : '',
            'away' => is_string($teams[1]['name'] ?? null) ? $teams[1]['name'] : '',
        ];
    }

    /** @return array<string, mixed>|null */
    private function currentGame(array $event): ?array
    {
        foreach ($event['match']['games'] ?? [] as $game) {
            if (is_array($game) && ($game['state'] ?? null) === 'inProgress') {
                return $game;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function latestWindow(string $gameId): ?array
    {
        if (preg_match('/^\d+$/', $gameId) !== 1) {
            return null;
        }

        $delay = max(10, (int) config('services.riot_esports.feed_delay_seconds', 20));
        $baseTime = CarbonImmutable::now('UTC')->subSeconds($delay);
        $baseTime = $baseTime
            ->setSecond(intdiv($baseTime->second, 10) * 10)
            ->setMicrosecond(0);
        $fallbackSeconds = [0, 20, 50, 110];

        foreach ($fallbackSeconds as $fallback) {
            $startingTime = $baseTime->subSeconds($fallback)->format('Y-m-d\TH:i:s.000\Z');
            $response = $this->windowRequest($gameId, $startingTime);

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            $frames = is_array($payload['frames'] ?? null) ? array_values($payload['frames']) : [];
            $frame = collect($frames)->last(fn (mixed $item): bool => is_array($item)
                && is_array($item['blueTeam'] ?? null)
                && is_array($item['redTeam'] ?? null));

            if (is_array($frame)) {
                return [
                    'frame' => $frame,
                    'metadata' => is_array($payload['gameMetadata'] ?? null)
                        ? $payload['gameMetadata']
                        : [],
                ];
            }
        }

        return null;
    }

    private function windowRequest(string $gameId, string $startingTime): Response
    {
        $url = rtrim((string) config('services.riot_esports.feed_base_url'), '/')
            .'/window/'.$gameId;

        return Http::acceptJson()
            ->withUserAgent('Mozilla/5.0 (compatible; AmandaBlogLineBot/1.0)')
            ->withHeaders($this->freshHeaders())
            ->timeout((int) config('services.riot_esports.timeout_seconds', 8))
            ->get($url, ['startingTime' => $startingTime]);
    }

    /** @param array{frame: array<string, mixed>, metadata: array<string, mixed>} $window */
    private function applyWindow(array $match, array $event, array $game, array $window): array
    {
        $frame = $window['frame'];
        $metadata = $window['metadata'];
        $blueName = $this->teamNameForId(
            $event,
            (string) ($metadata['blueTeamMetadata']['esportsTeamId'] ?? ''),
        );
        $redName = $this->teamNameForId(
            $event,
            (string) ($metadata['redTeamMetadata']['esportsTeamId'] ?? ''),
        );

        if ($blueName === null || $redName === null) {
            return $match;
        }

        $team1IsBlue = $this->matcher->firstTeamUsesHome($match, $blueName, $redName);
        $team1 = $team1IsBlue ? $frame['blueTeam'] : $frame['redTeam'];
        $team2 = $team1IsBlue ? $frame['redTeam'] : $frame['blueTeam'];

        if (is_numeric($team1['totalKills'] ?? null) && is_numeric($team2['totalKills'] ?? null)) {
            $match['score'] = $this->score($team1['totalKills'], $team2['totalKills']);
            $match['score_source'] = 'riot-esports';
        }

        $series = $this->seriesScore($event, $match);

        if (($match['series_score_source'] ?? null) !== 'odds-api' && $series !== null) {
            $match['series_score'] = $series;
            $match['series_score_source'] = 'riot-esports';
        }

        $match['is_live'] = true;
        $match['live_game_id'] = (string) $game['id'];
        $match['live_game_number'] = is_numeric($game['number'] ?? null) ? (int) $game['number'] : null;
        $match['live_frame_at'] = is_string($frame['rfc460Timestamp'] ?? null)
            ? $frame['rfc460Timestamp']
            : null;
        $match['live_score_received_at'] = now('UTC')->toIso8601String();

        return $match;
    }

    private function applyCompletedEvent(array $match, array $event): array
    {
        $series = $this->seriesScore($event, $match);

        if (($match['series_score_source'] ?? null) !== 'odds-api' && $series !== null) {
            $match['series_score'] = $series;
            $match['series_score_source'] = 'riot-esports';
        }

        $match['is_live'] = false;
        $match['live_status_source'] = 'riot-esports';

        return $match;
    }

    private function teamNameForId(array $event, string $teamId): ?string
    {
        foreach ($event['matchTeams'] ?? [] as $team) {
            if (! is_array($team) || ! is_string($team['name'] ?? null)) {
                continue;
            }

            $eventTeamId = (string) ($team['id'] ?? '');
            $eventTeamId = str_contains($eventTeamId, ':')
                ? (string) strrchr($eventTeamId, ':')
                : $eventTeamId;
            $eventTeamId = ltrim($eventTeamId, ':');

            if ($eventTeamId === $teamId) {
                return $team['name'];
            }
        }

        return null;
    }

    private function seriesScore(array $event, array $match): ?string
    {
        $teams = is_array($event['matchTeams'] ?? null) ? array_values($event['matchTeams']) : [];

        if (! is_numeric($teams[0]['result']['gameWins'] ?? null)
            || ! is_numeric($teams[1]['result']['gameWins'] ?? null)) {
            return null;
        }

        $team1UsesHome = $this->matcher->firstTeamUsesHome(
            $match,
            (string) ($teams[0]['name'] ?? ''),
            (string) ($teams[1]['name'] ?? ''),
        );

        return $team1UsesHome
            ? $this->score($teams[0]['result']['gameWins'], $teams[1]['result']['gameWins'])
            : $this->score($teams[1]['result']['gameWins'], $teams[0]['result']['gameWins']);
    }

    private function containsLiveLolMatch(array $matches): bool
    {
        return collect($matches)->contains(fn (array $match): bool => ($match['game'] ?? null) === 'lol'
            && ($match['is_live'] ?? false));
    }

    /** @return array<string, string> */
    private function freshHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-store',
            'Pragma' => 'no-cache',
        ];
    }

    private function score(mixed $left, mixed $right): string
    {
        return (int) $left.'：'.(int) $right;
    }
}
