<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class Bo3ScheduleService
{
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
        $cacheKey = "bo3-schedule:v3:{$game}:{$dateString}:{$tierKey}";

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
            ->mapWithKeys(fn (array $match): array => [$this->matchKey($match) => true])
            ->all();

        foreach ($tableMatches as $match) {
            $key = $this->matchKey($match);

            if (! isset($knownMatches[$key])) {
                $structuredMatches[] = $match;
                $knownMatches[$key] = true;
            }
        }

        return collect($structuredMatches)
            ->sortBy('start_at')
            ->values()
            ->all();
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
     * @return array<string, array{tournament: string, format: string}>
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

            if ($path !== '' && trim($name ?? '') !== '') {
                $result[$path] = [
                    'tournament' => trim($name),
                    'format' => isset($formatMatch[1]) ? 'BO'.$formatMatch[1] : '未知',
                ];
            }
        }

        return $result;
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
