<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OddsApiService
{
    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches, CarbonImmutable $date): array
    {
        $apiKey = (string) config('services.odds.api_key');

        if ($matches === [] || $apiKey === '') {
            return $this->withoutOdds($matches);
        }

        try {
            $events = $this->eventsForDate($date, $apiKey);
            $matchedEvents = $this->matchEvents($matches, $events);
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
     * @return array<int, array<string, mixed>>
     */
    private function eventsForDate(CarbonImmutable $date, string $apiKey): array
    {
        $response = Http::acceptJson()
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store',
                'Pragma' => 'no-cache',
            ])
            ->timeout((int) config('services.odds.timeout_seconds', 10))
            ->get(rtrim((string) config('services.odds.base_url'), '/').'/events', [
                'apiKey' => $apiKey,
                'sport' => 'esports',
                'status' => 'pending,live',
                'from' => $date->startOfDay()->utc()->toIso8601String(),
                'to' => $date->endOfDay()->utc()->toIso8601String(),
            ]);

        if (! $response->successful()) {
            Log::warning('Odds API events request failed.', ['status' => $response->status()]);

            return [];
        }

        $events = $response->json();

        return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
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

            foreach ($events as $event) {
                if (! isset($event['id'], $event['home'], $event['away'], $event['date'])) {
                    continue;
                }

                $eventTime = CarbonImmutable::parse($event['date']);

                if (abs($match['start_at']->utc()->diffInMinutes($eventTime, false)) > 360) {
                    continue;
                }

                $direct = ($this->similarity($match['team1'], $event['home']) + $this->similarity($match['team2'], $event['away'])) / 2;
                $reverse = ($this->similarity($match['team1'], $event['away']) + $this->similarity($match['team2'], $event['home'])) / 2;
                $score = max($direct, $reverse);

                if ($score >= 0.72 && $score > $bestScore) {
                    $best = $event;
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

        $response = Http::acceptJson()
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('services.odds.timeout_seconds', 10))
            ->get(rtrim((string) config('services.odds.base_url'), '/').'/odds/multi', [
                'apiKey' => $apiKey,
                'eventIds' => implode(',', array_slice($ids, 0, 10)),
                'bookmakers' => $bookmakers,
            ]);

        if (! $response->successful()) {
            Log::warning('Odds API odds request failed.', ['status' => $response->status()]);

            return [];
        }

        return collect($response->json())
            ->filter(fn (mixed $item): bool => is_array($item) && isset($item['id']))
            ->keyBy(fn (array $item): string => (string) $item['id'])
            ->all();
    }

    private function selectedBookmakers(string $apiKey): string
    {
        $cacheKey = 'odds-api:selected-bookmakers';

        try {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (Throwable) {
            // Cache is optional for this integration.
        }

        $response = Http::acceptJson()
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('services.odds.timeout_seconds', 10))
            ->get(rtrim((string) config('services.odds.base_url'), '/').'/bookmakers/selected', [
                'apiKey' => $apiKey,
            ]);

        if (! $response->successful()) {
            Log::warning('Odds API selected bookmakers request failed.', ['status' => $response->status()]);

            return '';
        }

        $bookmakers = collect($response->json('bookmakers'))
            ->filter(fn (mixed $bookmaker): bool => is_string($bookmaker) && trim($bookmaker) !== '')
            ->map(fn (string $bookmaker): string => trim($bookmaker))
            ->implode(',');

        if ($bookmakers !== '') {
            try {
                Cache::put($cacheKey, $bookmakers, 3600);
            } catch (Throwable) {
                // Cache is optional for this integration.
            }
        }

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

                if (! is_array($line)) {
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

        $team1IsHome = $this->similarity($match['team1'], $event['home']) >= $this->similarity($match['team1'], $event['away']);
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
