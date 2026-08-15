<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Bo3ScheduleService
{
    private const DISCIPLINE_IDS = [
        'valorant' => 2,
    ];

    private const PATHS = [
        'cs' => '/matches/current',
        'valorant' => '/valorant/matches/current',
        'lol' => '/lol/matches/current',
    ];

    /**
     * @return array<int, array{name: string, team1: string, team2: string, tournament: string, format: string, start_at: CarbonImmutable, url: string}>
     */
    public function forDate(string $game, CarbonImmutable $date, array $tiers = ['s', 'a']): array
    {
        if (! isset(self::PATHS[$game])) {
            throw new RuntimeException("Unsupported game: {$game}");
        }

        $tiers = $this->normalizeTiers($tiers);
        $dateString = $date->format('Y-m-d');
        $tierKey = $tiers === [] ? 'all' : implode(',', $tiers);
        $cacheKey = "bo3-schedule:v6:{$game}:{$dateString}:{$tierKey}";

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $matches = $this->fetch($game, $dateString, $tiers);

        try {
            Cache::put(
                $cacheKey,
                $matches,
                (int) config('services.bo3.cache_seconds', 300),
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $matches;
    }

    public function filteredUrl(string $game, CarbonImmutable $date, array $tiers = ['s', 'a']): string
    {
        if (! isset(self::PATHS[$game])) {
            throw new RuntimeException("Unsupported game: {$game}");
        }

        $tiers = $this->normalizeTiers($tiers);
        $url = rtrim((string) config('services.bo3.base_url'), '/').self::PATHS[$game].'?';
        $query = [];

        if ($tiers !== []) {
            $query[] = 'tiers='.implode(',', array_map('rawurlencode', $tiers));
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');

        if ($date->isSameDay(CarbonImmutable::now($timezone))) {
            $query[] = 'period';
        } else {
            $query[] = 'date='.$date->format('Y-m-d');
        }

        return $url.implode('&', $query);
    }

    /**
     * @return array<int, array{name: string, team1: string, team2: string, tournament: string, format: string, start_at: CarbonImmutable, url: string}>
     */
    private function fetch(string $game, string $date, array $tiers): array
    {
        $query = ['date' => $date];

        if ($tiers !== []) {
            $query['tiers'] = implode(',', $tiers);
        }

        $response = Http::accept('text/html')
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('services.bo3.timeout_seconds', 10))
            ->retry(2, 200)
            ->get(rtrim((string) config('services.bo3.base_url'), '/').self::PATHS[$game], $query);

        $response->throw();

        if (! preg_match('/<script\b[^>]*\bid=["\']micro-markup["\'][^>]*>(.*?)<\/script>/is', $response->body(), $matches)) {
            throw new RuntimeException('bo3.gg schedule data was not found.');
        }

        $events = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $metadata = $this->extractMatchMetadata($response->body());

        $structuredMatches = collect($events)
            ->filter(fn (mixed $event): bool => is_array($event)
                && ($event['@type'] ?? null) === 'SportsEvent'
                && isset($event['name'], $event['startDate'], $event['url']))
            ->map(function (array $event) use ($timezone, $metadata): array {
                $name = preg_replace('/\s+/u', ' ', (string) $event['name']);
                $name = trim($name ?? (string) $event['name']);
                $teams = preg_split('/\s+vs\s+/iu', $name, 2);
                $path = (string) parse_url((string) $event['url'], PHP_URL_PATH);

                return [
                    'name' => $name,
                    'team1' => trim($teams[0] ?? $name),
                    'team2' => trim($teams[1] ?? ''),
                    'tournament' => $metadata[$path]['tournament'] ?? '未知賽事',
                    'format' => $metadata[$path]['format'] ?? '未知',
                    'is_live' => $metadata[$path]['is_live'] ?? false,
                    'score' => $metadata[$path]['score'] ?? null,
                    'start_at' => CarbonImmutable::parse($event['startDate'])->setTimezone($timezone),
                    'url' => (string) $event['url'],
                ];
            })
            ->filter(fn (array $event): bool => $event['start_at']->format('Y-m-d') === $date)
            ->all();

        // bo3.gg leaves matches with an undecided participant (for example,
        // "TBD vs JD Gaming") out of its JSON-LD SportsEvent list. The visible
        // schedule table still contains those rows, so merge it in as a fallback.
        $tableMatches = $this->extractTableMatches($response->body(), $game, $date, $timezone);
        $knownMatches = collect($structuredMatches)
            ->mapWithKeys(fn (array $match, int $index): array => [$this->matchKey($match) => $index])
            ->all();

        foreach ($tableMatches as $match) {
            $key = $this->matchKey($match);

            if (! array_key_exists($key, $knownMatches)) {
                $structuredMatches[] = $match;
                $knownMatches[$key] = array_key_last($structuredMatches);
            }
        }

        // The current schedule page is paginated and can omit matches that
        // started earlier in the same local day. This is especially visible
        // on busy VALORANT days. Merge the date-bounded API result so the
        // daily schedule does not depend on whichever page rows were SSR'd.
        foreach ($this->extractApiMatches($game, $date, $timezone, $tiers) as $match) {
            $key = $this->matchKey($match);

            if (! array_key_exists($key, $knownMatches)) {
                $structuredMatches[] = $match;
                $knownMatches[$key] = array_key_last($structuredMatches);

                continue;
            }

            $index = $knownMatches[$key];
            $structuredMatches[$index]['is_live'] = ($structuredMatches[$index]['is_live'] ?? false)
                || ($match['is_live'] ?? false);
            $structuredMatches[$index]['score'] ??= $match['score'] ?? null;
        }

        $structuredMatches = $this->enrichMissingFormats($structuredMatches);

        return collect($structuredMatches)
            ->sortBy('start_at')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, team1: string, team2: string, tournament: string, format: string, start_at: CarbonImmutable, url: string}>
     */
    private function extractApiMatches(string $game, string $date, string $timezone, array $tiers): array
    {
        $disciplineId = self::DISCIPLINE_IDS[$game] ?? null;

        if ($disciplineId === null) {
            return [];
        }

        $start = CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
        $query = [
            'page' => ['offset' => 0, 'limit' => 100],
            'sort' => 'start_date',
            'with' => 'teams,tournament',
            'filter' => [
                'matches.discipline_id' => ['eq' => $disciplineId],
                // bo3.gg supports gt/lt but not gte/lte. Subtract one second
                // so a match scheduled exactly at local midnight is included.
                'matches.start_date' => [
                    'gt' => $start->subSecond()->toIso8601String(),
                    'lt' => $start->addDay()->toIso8601String(),
                ],
            ],
        ];

        if ($tiers !== []) {
            $query['filter']['matches.tier'] = ['in' => implode(',', $tiers)];
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent('AmandaBlogLineBot/1.0')
                ->timeout((int) config('services.bo3.timeout_seconds', 10))
                ->retry(2, 200)
                ->get($this->apiUrl().'/matches', $query);

            if (! $response->successful()) {
                Log::warning('bo3.gg complete daily schedule request failed.', [
                    'game' => $game,
                    'date' => $date,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $results = $response->json('results');

            if (! is_array($results)) {
                return [];
            }

            return collect($results)
                ->filter(fn (mixed $match): bool => is_array($match)
                    && is_string($match['slug'] ?? null)
                    && is_string($match['start_date'] ?? null)
                    && is_string($match['team1']['name'] ?? null)
                    && is_string($match['team2']['name'] ?? null))
                ->map(function (array $match) use ($game, $timezone): array {
                    $team1 = $this->normalizeText($match['team1']['name']);
                    $team2 = $this->normalizeText($match['team2']['name']);
                    $boType = is_numeric($match['bo_type'] ?? null) && (int) $match['bo_type'] > 0
                        ? 'BO'.(int) $match['bo_type']
                        : '未知';

                    $matchesPath = preg_replace('~/current$~', '', self::PATHS[$game]) ?? self::PATHS[$game];

                    return [
                        'name' => "{$team1} vs {$team2}",
                        'team1' => $team1,
                        'team2' => $team2,
                        'tournament' => $this->normalizeText((string) ($match['tournament']['name'] ?? '')) ?: '未知賽事',
                        'format' => $boType,
                        'is_live' => $this->isLiveStatus($match['status'] ?? null),
                        'score' => $this->scoreFromValues(
                            $match['team1_score'] ?? null,
                            $match['team2_score'] ?? null,
                        ),
                        'start_at' => CarbonImmutable::parse($match['start_date'])->setTimezone($timezone),
                        'url' => $this->baseUrl().$matchesPath.'/'.rawurlencode($match['slug']),
                    ];
                })
                ->all();
        } catch (Throwable $exception) {
            Log::warning('bo3.gg complete daily schedule connection failed.', [
                'game' => $game,
                'date' => $date,
                'type' => $exception::class,
            ]);

            return [];
        }
    }

    /**
     * Live rows replace the visible BO type with the current score. The match
     * detail endpoint keeps exposing bo_type for both upcoming and live games,
     * so use it only for rows whose format could not be read from the page.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function enrichMissingFormats(array $matches): array
    {
        $slugs = [];

        foreach ($matches as $index => $match) {
            if (preg_match('/^BO\d+$/i', trim((string) ($match['format'] ?? ''))) === 1) {
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

        $uniqueSlugs = array_values(array_unique($slugs));
        $responses = Http::pool(function (Pool $pool) use ($uniqueSlugs): void {
            foreach ($uniqueSlugs as $slug) {
                $pool->as($slug)
                    ->acceptJson()
                    ->withUserAgent('AmandaBlogLineBot/1.0')
                    ->timeout((int) config('services.bo3.timeout_seconds', 10))
                    ->get($this->baseUrl().'/api/v1/matches/'.rawurlencode($slug));
            }
        }, 5);

        foreach ($slugs as $index => $slug) {
            $response = $responses[$slug] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                Log::warning('bo3.gg match format request failed.', [
                    'slug' => $slug,
                    'status' => $response instanceof Response ? $response->status() : null,
                ]);

                continue;
            }

            $boType = $response->json('bo_type');

            if (is_numeric($boType) && (int) $boType > 0) {
                $matches[$index]['format'] = 'BO'.(int) $boType;
            }

            if ($this->isLiveStatus($response->json('status'))) {
                $matches[$index]['is_live'] = true;
            }

            $score = $this->scoreFromValues(
                $response->json('team1_score'),
                $response->json('team2_score'),
            );

            if ($score !== null && ($matches[$index]['score'] ?? null) === null) {
                $matches[$index]['score'] = $score;
            }
        }

        return $matches;
    }

    private function matchSlug(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! preg_match('~/matches/([^/]+)$~', rtrim($path, '/'), $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.bo3.base_url'), '/');
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.bo3.api_url', 'https://api.bo3.gg/api/v1'), '/');
    }

    /**
     * Extract schedule rows that may be missing from bo3.gg's JSON-LD data.
     *
     * Times in the server-rendered table are UTC. The browser changes them to
     * the visitor's timezone after hydration, so convert them before returning.
     *
     * @return array<int, array{name: string, team1: string, team2: string, tournament: string, format: string, start_at: CarbonImmutable, url: string}>
     */
    private function extractTableMatches(string $html, string $game, string $date, string $timezone): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " table-row--upcoming ")]');
        $result = [];

        foreach ($rows ?: [] as $row) {
            $teams = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " team-name ")]', $row);
            $time = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " time ")]', $row)?->item(0);

            if ($teams === false || $teams->length < 2 || $time === null) {
                continue;
            }

            $team1 = $this->normalizeText($teams->item(0)?->textContent ?? '');
            $team2 = $this->normalizeText($teams->item(1)?->textContent ?? '');
            $startTime = $this->normalizeText($time->textContent);

            if ($team1 === '' || $team2 === '' || ! preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
                continue;
            }

            try {
                $startAt = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    "{$date} {$startTime}",
                    'UTC',
                )->setTimezone($timezone);
            } catch (Throwable) {
                continue;
            }

            if ($startAt->format('Y-m-d') !== $date) {
                continue;
            }

            $format = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " bo-type ")]', $row)?->item(0);
            $tournament = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " tournament-name ")]', $row)?->item(0);
            $matchLink = $xpath->query('.//a[contains(@href, "/matches/")]', $row)?->item(0);
            $path = $matchLink?->getAttribute('href') ?? '';
            $url = str_starts_with($path, 'http')
                ? $path
                : rtrim((string) config('services.bo3.base_url'), '/').($path !== '' ? $path : self::PATHS[$game].'?date='.$date);

            $result[] = [
                'name' => "{$team1} vs {$team2}",
                'team1' => $team1,
                'team2' => $team2,
                'tournament' => $this->normalizeText($tournament?->textContent ?? '') ?: '未知賽事',
                'format' => mb_strtoupper($this->normalizeText($format?->textContent ?? '')) ?: '未知',
                'is_live' => false,
                'score' => null,
                'start_at' => $startAt,
                'url' => $url,
            ];
        }

        return $result;
    }

    /** @param array{team1: string, team2: string, start_at: CarbonImmutable} $match */
    private function matchKey(array $match): string
    {
        $teams = [
            mb_strtolower($this->normalizeText($match['team1'])),
            mb_strtolower($this->normalizeText($match['team2'])),
        ];
        sort($teams, SORT_STRING);

        return implode('|', [...$teams, $match['start_at']->utc()->format('Y-m-d H:i')]);
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array<string, array{tournament: string, format: string, is_live: bool, score: ?string}>
     */
    private function extractMatchMetadata(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " table-row ")]');
        $result = [];

        foreach ($rows ?: [] as $row) {
            $matchLink = $xpath->query('.//a[contains(@href, "/matches/")]', $row)?->item(0);
            $tournament = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " tournament-name ")]', $row)?->item(0);

            if ($matchLink === null || $tournament === null) {
                continue;
            }

            $path = (string) parse_url($matchLink->getAttribute('href'), PHP_URL_PATH);
            $name = preg_replace('/\s+/u', ' ', $tournament->textContent);
            preg_match('/Bo([1-5])/i', $row->textContent, $formatMatch);
            $rowClasses = ' '.$this->normalizeText($row->getAttribute('class')).' ';
            $isLive = str_contains($rowClasses, ' table-row--current ')
                || str_contains($rowClasses, ' table-row--live ');
            $scoreNode = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " c-match-score ")]',
                $row,
            )?->item(0);

            if ($path !== '' && trim($name ?? '') !== '') {
                $result[$path] = [
                    'tournament' => trim($name),
                    'format' => isset($formatMatch[1]) ? 'BO'.$formatMatch[1] : '未知',
                    'is_live' => $isLive,
                    'score' => $isLive ? $this->normalizeScore($scoreNode?->textContent ?? '') : null,
                ];
            }
        }

        return $result;
    }

    private function isLiveStatus(mixed $status): bool
    {
        return is_string($status)
            && in_array(mb_strtolower(trim($status)), ['current', 'live', 'in_progress'], true);
    }

    private function scoreFromValues(mixed $team1Score, mixed $team2Score): ?string
    {
        if (! is_numeric($team1Score) || ! is_numeric($team2Score)) {
            return null;
        }

        return (int) $team1Score.'：'.(int) $team2Score;
    }

    private function normalizeScore(string $score): ?string
    {
        $score = $this->normalizeText($score);

        if (preg_match('/(\d+)\s*[-:：]\s*(\d+)/u', $score, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1].'：'.(int) $matches[2];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTiers(array $tiers): array
    {
        $allowed = ['s', 'a', 'b', 'c', 'd'];

        return collect($tiers)
            ->map(fn (mixed $tier): string => mb_strtolower(trim((string) $tier)))
            ->filter(fn (string $tier): bool => in_array($tier, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }
}
