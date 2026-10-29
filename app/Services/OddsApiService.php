<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class OddsApiService
{
    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches, CarbonImmutable $date, ?CarbonImmutable $endDate = null): array
    {
        $apiKey = (string) config('services.odds.api_key');
        $matches = $this->withoutOdds($matches);

        if ($matches === [] || $apiKey === '') {
            return $matches;
        }

        $groups = [];
        foreach ($matches as $index => $match) {
            if (($match['game'] ?? '') === 'mlb') {
                continue;
            }
            $isNba = ($match['game'] ?? '') === 'nba';
            if ($isNba && (($match['is_finished'] ?? false) || ($match['is_cancelled'] ?? false) || ($match['time_tbd'] ?? false))) {
                continue;
            }
            $groups[$isNba ? 'basketball' : 'esports'][$index] = $match;
        }
        if ($groups === []) {
            return $matches;
        }

        try {
            $events = $this->eventsForDate(array_keys($groups), $date, $endDate ?? $date, $apiKey);
            $matchedEvents = [];
            foreach ($groups as $sport => $group) {
                $matchedEvents += $this->matchEvents($group, $events[$sport] ?? []);
            }
            $odds = $this->fetchOdds($matchedEvents, $apiKey);

            foreach ($matches as $index => $match) {
                $event = $matchedEvents[$index] ?? null;
                $matches[$index]['odds'] = $event === null
                    ? null
                    : $this->bestMoneyline($match, $event, $odds[(string) $event['id']] ?? null);
            }

            return $matches;
        } catch (ConnectionException) {
            Log::warning('Odds API connection failed.');
        } catch (Throwable $exception) {
            Log::warning('Odds API enrichment failed.', ['type' => $exception::class]);
        }

        return $this->withoutOdds($matches);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function eventsForDate(array $sports, CarbonImmutable $startDate, CarbonImmutable $endDate, string $apiKey): array
    {
        $responses = ScheduleHttp::pool(function (Pool $pool) use ($sports, $startDate, $endDate, $apiKey): void {
            foreach ($sports as $sport) {
                $query = [
                    'apiKey' => $apiKey,
                    'sport' => $sport,
                    'status' => 'pending,live',
                    'from' => $startDate->startOfDay()->utc()->toIso8601String(),
                    'to' => $endDate->endOfDay()->utc()->toIso8601String(),
                ];
                if ($sport === 'basketball') {
                    $query['league'] = 'usa-nba';
                    $query['limit'] = 1000; // Covers every NBA game in the seven-day command window.
                }
                $pool->as($sport)->acceptJson()->withUserAgent('AmandaBlogLineBot/1.0')
                    ->withHeaders(['Cache-Control' => 'no-cache, no-store', 'Pragma' => 'no-cache'])
                    ->connectTimeout(3)->timeout((int) config('services.odds.timeout_seconds', 10))
                    ->get(rtrim((string) config('services.odds.base_url'), '/').'/events', $query);
            }
        }, concurrency: 5);

        $events = [];
        foreach ($sports as $sport) {
            $response = $responses[$sport] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                Log::warning('Odds API events request failed.', ['sport' => $sport, 'status' => $response instanceof Response ? $response->status() : null]);

                continue;
            }
            $payload = $response->json();
            $events[$sport] = is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
        }

        return $events;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function matchEvents(array $matches, array $events): array
    {
        $result = [];

        foreach ($matches as $index => $match) {
            $best = null;
            $bestScore = 0.0;
            $isNba = ($match['game'] ?? '') === 'nba';

            foreach ($events as $event) {
                if (! isset($event['id']) || ! is_string($event['home'] ?? null)
                    || ! is_string($event['away'] ?? null) || ! is_string($event['date'] ?? null) || $event['date'] === '') {
                    continue;
                }
                try {
                    $eventTime = CarbonImmutable::parse($event['date']);
                } catch (Throwable) {
                    continue;
                }

                if (abs($match['start_at']->utc()->diffInMinutes($eventTime, false)) > 360) {
                    continue;
                }

                if ($isNba) {
                    if (($event['sport']['slug'] ?? '') !== 'basketball' || ($event['league']['slug'] ?? '') !== 'usa-nba') {
                        continue;
                    }
                    $team1 = $this->normalizeNbaName((string) ($match['team1_name'] ?? ''));
                    $team2 = $this->normalizeNbaName((string) ($match['team2_name'] ?? ''));
                    $home = $this->normalizeNbaName($event['home']);
                    $away = $this->normalizeNbaName($event['away']);
                    if ($team1 === '' || $team2 === '' || $team1 === $team2) {
                        continue;
                    }
                    // Exact team pairs avoid confusing Lakers with Clippers,
                    // NBA with esports, or a known team with another opponent.
                    $direct = (int) ($team1 === $home && $team2 === $away);
                    $reverse = (int) ($team1 === $away && $team2 === $home);
                } else {
                    $direct = ($this->similarity($match['team1'], $event['home']) + $this->similarity($match['team2'], $event['away'])) / 2;
                    $reverse = ($this->similarity($match['team1'], $event['away']) + $this->similarity($match['team2'], $event['home'])) / 2;
                }
                $score = max($direct, $reverse);

                if ($score >= 0.72 && $score > $bestScore) {
                    $best = $event;
                    $best['_team1_is_home'] = $direct >= $reverse;
                    $bestScore = $score;
                }
            }

            if ($best !== null) {
                $result[$index] = $best;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, array<string, mixed>>
     */
    private function fetchOdds(array $events, string $apiKey): array
    {
        $ids = array_values(array_unique(array_map(fn (array $event): string => (string) $event['id'], $events)));

        if ($ids === []) {
            return [];
        }

        $bookmakers = trim((string) config('services.odds.bookmakers'));

        if ($bookmakers === '') {
            $bookmakers = $this->selectedBookmakers($apiKey);
        }

        if ($bookmakers === '') {
            return [];
        }

        $responses = ScheduleHttp::pool(function (Pool $pool) use ($ids, $apiKey, $bookmakers): void {
            foreach (array_chunk($ids, 10) as $index => $batch) {
                $pool->as((string) $index)->acceptJson()->withUserAgent('AmandaBlogLineBot/1.0')
                    ->withHeaders(['Cache-Control' => 'no-cache, no-store', 'Pragma' => 'no-cache'])
                    ->connectTimeout(3)->timeout((int) config('services.odds.timeout_seconds', 10))
                    ->get(rtrim((string) config('services.odds.base_url'), '/').'/odds/multi', [
                        'apiKey' => $apiKey,
                        'eventIds' => implode(',', $batch),
                        'bookmakers' => $bookmakers,
                    ]);
            }
        }, concurrency: 5);

        $odds = [];
        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                Log::warning('Odds API odds request failed.', ['status' => $response instanceof Response ? $response->status() : null]);

                continue;
            }
            foreach (is_array($response->json()) ? $response->json() : [] as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $odds[(string) $item['id']] = $item;
                }
            }
        }

        return $odds;
    }

    private function selectedBookmakers(string $apiKey): string
    {
        $response = ScheduleHttp::pool(function (Pool $pool) use ($apiKey): void {
            $pool->as('bookmakers')->acceptJson()->withUserAgent('AmandaBlogLineBot/1.0')
                ->connectTimeout(3)->timeout((int) config('services.odds.timeout_seconds', 10))
                ->get(rtrim((string) config('services.odds.base_url'), '/').'/bookmakers/selected', [
                    'apiKey' => $apiKey,
                ]);
        })['bookmakers'];
        if ($response instanceof Throwable) {
            throw $response;
        }

        if (! $response->successful()) {
            Log::warning('Odds API selected bookmakers request failed.', ['status' => $response->status()]);

            return '';
        }

        $bookmakers = collect($response->json('bookmakers'))
            ->filter(fn (mixed $bookmaker): bool => is_string($bookmaker) && trim($bookmaker) !== '')
            ->map(fn (string $bookmaker): string => trim($bookmaker))
            ->implode(',');

        return $bookmakers;
    }

    /**
     * @return array{team1: array{price: float, bookmaker: string}, team2: array{price: float, bookmaker: string}}|null
     */
    private function bestMoneyline(array $match, array $event, ?array $odds): ?array
    {
        if ($odds === null || ! is_array($odds['bookmakers'] ?? null)) {
            return null;
        }

        $lines = [];

        foreach ($odds['bookmakers'] as $bookmaker => $markets) {
            foreach (is_array($markets) ? $markets : [] as $market) {
                if (! is_array($market) || ! in_array(mb_strtoupper((string) ($market['name'] ?? '')), ['ML', 'MONEYLINE'], true)) {
                    continue;
                }

                $line = $market['odds'][0] ?? null;

                if (! is_array($line) || (($match['game'] ?? '') === 'nba' && isset($line['draw']))) {
                    continue;
                }

                if (is_numeric($line['home'] ?? null)
                    && is_numeric($line['away'] ?? null)
                    && (float) $line['home'] > 1
                    && (float) $line['away'] > 1) {
                    $lines[mb_strtolower((string) $bookmaker)] = [
                        'home' => (float) $line['home'],
                        'away' => (float) $line['away'],
                        'bookmaker' => (string) $bookmaker,
                    ];
                }
            }
        }

        $selected = null;
        $priority = array_filter(array_map(
            'trim',
            explode(',', (string) config('services.odds.bookmaker_priority', 'Stake,Bet365')),
        ));

        foreach ($priority as $bookmaker) {
            if (isset($lines[mb_strtolower($bookmaker)])) {
                $selected = $lines[mb_strtolower($bookmaker)];

                break;
            }
        }

        if ($selected === null) {
            return null;
        }

        $team1IsHome = $event['_team1_is_home'];
        $home = ['price' => $selected['home'], 'bookmaker' => $selected['bookmaker']];
        $away = ['price' => $selected['away'], 'bookmaker' => $selected['bookmaker']];

        return $team1IsHome
            ? ['team1' => $home, 'team2' => $away]
            : ['team1' => $away, 'team2' => $home];
    }

    private function similarity(string $left, string $right): float
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percentage);

        return $percentage / 100;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($name)) ?? '';
    }

    private function normalizeNbaName(string $name): string
    {
        $name = $this->normalizeName($name);

        return match ($name) {
            'losangeleslakers' => 'lalakers',
            'losangelesclippers' => 'laclippers',
            default => $name,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function withoutOdds(array $matches): array
    {
        return array_map(function (array $match): array {
            $match['odds'] = null;

            return $match;
        }, $matches);
    }
}
