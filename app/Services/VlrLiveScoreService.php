<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VlrLiveScoreService
{
    public function __construct(private readonly LiveMatchMatcher $matcher) {}

    /**
     * Directly requests VLR.gg for live Valorant match statuses and
     * in-progress round scores (小分比分). Deliberately stateless (no cache).
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        if (! $this->containsValorantMatch($matches)) {
            return $matches;
        }

        try {
            $liveEvents = $this->fetchLiveEvents();

            if ($liveEvents === []) {
                return $matches;
            }

            $matchedEvents = $this->matcher->match(
                $matches,
                $liveEvents,
                fn (array $event): array => [
                    'home' => is_string($event['home'] ?? null) ? $event['home'] : '',
                    'away' => is_string($event['away'] ?? null) ? $event['away'] : '',
                ],
                ['valorant'],
            );

            if ($matchedEvents === []) {
                return $matches;
            }

            $detailPages = $this->fetchDetailPages($matchedEvents);

            foreach ($matchedEvents as $index => $event) {
                $team1IsHome = $this->matcher->firstTeamUsesHome(
                    $matches[$index],
                    (string) ($event['home'] ?? ''),
                    (string) ($event['away'] ?? ''),
                );

                $matches[$index]['is_live'] = true;

                $seriesScore = $this->extractSeriesScore($event, $detailPages[$index] ?? null, $team1IsHome);
                if ($seriesScore !== null) {
                    $matches[$index]['series_score'] = $seriesScore;
                    $matches[$index]['series_score_source'] = 'vlr';
                }

                $roundScore = $this->extractRoundScore($detailPages[$index] ?? null, $team1IsHome);
                if ($roundScore !== null) {
                    $matches[$index]['score'] = $roundScore;
                    $matches[$index]['score_source'] = 'vlr';
                }
            }
        } catch (Throwable $exception) {
            Log::warning('VLR Valorant live scores connection failed.', [
                'type' => $exception::class,
            ]);
        }

        return $matches;
    }

    private function containsValorantMatch(array $matches): bool
    {
        return collect($matches)->contains(
            fn (array $match): bool => ($match['game'] ?? null) === 'valorant',
        );
    }

    /** @return array<int, array{url: string, home: string, away: string, series_home: ?string, series_away: ?string}> */
    private function fetchLiveEvents(): array
    {
        $matchesUrl = (string) config('services.vlr.matches_url', 'https://www.vlr.gg/matches');

        $response = Http::accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
            ->withHeaders($this->freshHeaders())
            ->timeout((int) config('services.vlr.timeout_seconds', 8))
            ->get($matchesUrl);

        if (! $response->successful()) {
            Log::warning('VLR matches list request failed.', [
                'status' => $response->status(),
            ]);

            return [];
        }

        return $this->extractLiveEventsFromHtml($response->body());
    }

    /** @return array<int, array{url: string, home: string, away: string, series_home: ?string, series_away: ?string}> */
    public function extractLiveEventsFromHtml(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $items = $xpath->query('//a[contains(@class, "match-item")]');
        $baseUrl = rtrim((string) config('services.vlr.base_url', 'https://www.vlr.gg'), '/');
        $events = [];

        foreach ($items ?: [] as $item) {
            $isLive = str_contains($item->getAttribute('class'), 'mod-live')
                || $xpath->query('.//*[contains(@class, "mod-live")]', $item)->length > 0
                || stripos($item->textContent, 'LIVE') !== false;

            if (! $isLive) {
                continue;
            }

            $teams = $xpath->query('.//div[contains(@class, "match-item-vs-team-name")]', $item);
            $scores = $xpath->query('.//div[contains(@class, "match-item-vs-team-score")]', $item);
            $home = trim($teams->item(0)?->textContent ?? '');
            $away = trim($teams->item(1)?->textContent ?? '');
            $score1 = trim($scores->item(0)?->textContent ?? '');
            $score2 = trim($scores->item(1)?->textContent ?? '');
            $href = $item->getAttribute('href');

            if ($home === '' || $away === '' || $href === '') {
                continue;
            }

            $url = str_starts_with($href, 'http') ? $href : $baseUrl.$href;

            $events[] = [
                'url' => $url,
                'home' => $home,
                'away' => $away,
                'series_home' => is_numeric($score1) ? $score1 : null,
                'series_away' => is_numeric($score2) ? $score2 : null,
            ];
        }

        return $events;
    }

    /**
     * @param  array<int, array{url: string, home: string, away: string, series_home: ?string, series_away: ?string}>  $matchedEvents
     * @return array<int, string>
     */
    private function fetchDetailPages(array $matchedEvents): array
    {
        $urls = [];
        foreach ($matchedEvents as $index => $event) {
            $urls[$index] = $event['url'];
        }

        $uniqueUrls = array_values(array_unique($urls));
        $responses = Http::pool(function (Pool $pool) use ($uniqueUrls): void {
            foreach ($uniqueUrls as $url) {
                $pool->as($url)
                    ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
                    ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                    ->withHeaders($this->freshHeaders())
                    ->timeout((int) config('services.vlr.timeout_seconds', 8))
                    ->get($url);
            }
        }, 5);

        $htmlMap = [];
        foreach ($uniqueUrls as $url) {
            $response = $responses[$url] ?? null;
            if ($response instanceof Response && $response->successful()) {
                $htmlMap[$url] = $response->body();
            }
        }

        $result = [];
        foreach ($matchedEvents as $index => $event) {
            if (isset($htmlMap[$event['url']])) {
                $result[$index] = $htmlMap[$event['url']];
            }
        }

        return $result;
    }

    private function extractSeriesScore(array $event, ?string $detailHtml, bool $team1IsHome): ?string
    {
        if ($detailHtml !== null) {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML($detailHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $xpath = new DOMXPath($document);
            $spHide = $xpath->query('//div[contains(@class, "match-header-vs-score")]//div[contains(@class, "sp-hide")]')->item(0);
            if ($spHide !== null) {
                $spans = $xpath->query('.//span[not(contains(@class, "colon"))]', $spHide);
                if ($spans->length >= 2) {
                    $s1 = trim($spans->item(0)->textContent);
                    $s2 = trim($spans->item(1)->textContent);
                    if (is_numeric($s1) && is_numeric($s2)) {
                        return $team1IsHome
                            ? $this->score((int) $s1, (int) $s2)
                            : $this->score((int) $s2, (int) $s1);
                    }
                }
            }
        }

        if (is_numeric($event['series_home'] ?? null) && is_numeric($event['series_away'] ?? null)) {
            return $team1IsHome
                ? $this->score((int) $event['series_home'], (int) $event['series_away'])
                : $this->score((int) $event['series_away'], (int) $event['series_home']);
        }

        return null;
    }

    public function extractRoundScore(?string $detailHtml, bool $team1IsHome): ?string
    {
        if ($detailHtml === null || trim($detailHtml) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($detailHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $games = $xpath->query('//div[contains(@class, "vm-stats-game")]');

        $activeScore = null;
        $uncompletedScores = [];

        foreach ($games ?: [] as $game) {
            $gameId = $game->getAttribute('data-game-id');
            if ($gameId === '' || $gameId === 'all') {
                continue;
            }

            $scoreNodes = $xpath->query('.//div[contains(@class, "score")]', $game);
            if ($scoreNodes->length < 2) {
                continue;
            }

            $s1 = trim($scoreNodes->item(0)->textContent);
            $s2 = trim($scoreNodes->item(1)->textContent);

            if (! is_numeric($s1) || ! is_numeric($s2)) {
                continue;
            }

            $score1 = (int) $s1;
            $score2 = (int) $s2;
            $classes = $game->getAttribute('class');
            $isActive = str_contains($classes, 'mod-active') || str_contains($classes, 'active');

            if ($isActive) {
                $activeScore = [$score1, $score2];
                break;
            }

            $isCompleted = (max($score1, $score2) >= 13 && abs($score1 - $score2) >= 2)
                || max($score1, $score2) >= 15;

            if (! $isCompleted) {
                $uncompletedScores[] = [$score1, $score2];
            }
        }

        if ($activeScore === null && $uncompletedScores !== []) {
            $activeScore = collect($uncompletedScores)->first(
                fn (array $pair): bool => $pair[0] > 0 || $pair[1] > 0,
            ) ?? $uncompletedScores[0];
        }

        if ($activeScore !== null) {
            return $team1IsHome
                ? $this->score($activeScore[0], $activeScore[1])
                : $this->score($activeScore[1], $activeScore[0]);
        }

        return null;
    }

    private function score(int $left, int $right): string
    {
        return $left.'：'.$right;
    }

    /** @return array<string, string> */
    private function freshHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, no-store',
            'Pragma' => 'no-cache',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }
}
