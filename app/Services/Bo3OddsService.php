<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Bo3OddsService
{
    /**
     * Fill matches that have no Odds-API.io line with bo3.gg's single-bookmaker
     * moneyline. Existing odds are always preserved.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrichMissing(array $matches): array
    {
        if ((string) config('services.odds.api_key') === '') {
            return $matches;
        }

        $candidates = [];

        foreach ($matches as $index => $match) {
            if (($match['odds'] ?? null) !== null) {
                continue;
            }

            $slug = $this->matchSlug((string) ($match['url'] ?? ''));

            if ($slug === null) {
                continue;
            }

            try {
                $detail = $this->matchDetail($slug);
                $candidate = $this->moneyline($match, $detail);

                if ($candidate !== null) {
                    $candidates[$index] = $candidate;
                }
            } catch (ConnectionException) {
                Log::warning('bo3.gg odds connection failed.', ['slug' => $slug]);
            } catch (Throwable $exception) {
                Log::warning('bo3.gg odds fallback failed.', [
                    'slug' => $slug,
                    'type' => $exception::class,
                ]);
            }
        }

        if ($candidates === []) {
            return $matches;
        }

        $providers = $this->providerNames();

        foreach ($candidates as $index => $candidate) {
            $bookmaker = $providers[$candidate['provider_id']] ?? 'bo3.gg';
            $matches[$index]['odds'] = [
                'team1' => [
                    'price' => $candidate['team1'],
                    'bookmaker' => $bookmaker,
                ],
                'team2' => [
                    'price' => $candidate['team2'],
                    'bookmaker' => $bookmaker,
                ],
            ];
        }

        return $matches;
    }

    /** @return array<string, mixed> */
    private function matchDetail(string $slug): array
    {
        $cacheKey = 'bo3-odds:match:'.$slug;

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Cache is optional for this fallback.
        }

        $response = Http::acceptJson()
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('services.bo3.timeout_seconds', 10))
            ->retry(2, 200)
            ->get($this->baseUrl().'/api/v1/matches/'.rawurlencode($slug));

        if (! $response->successful()) {
            Log::warning('bo3.gg match odds request failed.', [
                'slug' => $slug,
                'status' => $response->status(),
            ]);

            return [];
        }

        $detail = $response->json();
        $detail = is_array($detail) ? $detail : [];

        try {
            Cache::put(
                $cacheKey,
                $detail,
                (int) config('services.odds.cache_seconds', 60),
            );
        } catch (Throwable) {
            // Cache is optional for this fallback.
        }

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $detail
     * @return array{team1: float, team2: float, provider_id: int}|null
     */
    private function moneyline(array $match, array $detail): ?array
    {
        $updates = $detail['bet_updates'] ?? null;
        $team1Line = is_array($updates) ? ($updates['team_1'] ?? null) : null;
        $team2Line = is_array($updates) ? ($updates['team_2'] ?? null) : null;

        if (! is_array($team1Line)
            || ! is_array($team2Line)
            || ($team1Line['active'] ?? false) !== true
            || ($team2Line['active'] ?? false) !== true
            || ! is_numeric($team1Line['coeff'] ?? null)
            || ! is_numeric($team2Line['coeff'] ?? null)
            || (float) $team1Line['coeff'] <= 1
            || (float) $team2Line['coeff'] <= 1
            || ! is_numeric($updates['bet_provider_id'] ?? null)) {
            return null;
        }

        $detailTeam1 = (string) ($detail['team1']['name'] ?? $team1Line['name'] ?? '');
        $detailTeam2 = (string) ($detail['team2']['name'] ?? $team2Line['name'] ?? '');
        $direct = $this->similarity((string) $match['team1'], $detailTeam1)
            + $this->similarity((string) $match['team2'], $detailTeam2);
        $reverse = $this->similarity((string) $match['team1'], $detailTeam2)
            + $this->similarity((string) $match['team2'], $detailTeam1);
        $first = (float) $team1Line['coeff'];
        $second = (float) $team2Line['coeff'];

        return [
            'team1' => $direct >= $reverse ? $first : $second,
            'team2' => $direct >= $reverse ? $second : $first,
            'provider_id' => (int) $updates['bet_provider_id'],
        ];
    }

    /** @return array<int, string> */
    private function providerNames(): array
    {
        $cacheKey = 'bo3-odds:providers';

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Cache is optional for this fallback.
        }

        try {
            $response = Http::acceptJson()
                ->withUserAgent('AmandaBlogLineBot/1.0')
                ->timeout((int) config('services.bo3.timeout_seconds', 10))
                ->retry(2, 200)
                ->get($this->baseUrl().'/api/v1/bet_providers');

            if (! $response->successful()) {
                return [];
            }

            $providers = collect($response->json('results'))
                ->filter(fn (mixed $provider): bool => is_array($provider)
                    && is_numeric($provider['id'] ?? null)
                    && is_string($provider['name'] ?? null)
                    && trim($provider['name']) !== '')
                ->mapWithKeys(fn (array $provider): array => [
                    (int) $provider['id'] => trim($provider['name']),
                ])
                ->all();

            try {
                Cache::put($cacheKey, $providers, 3600);
            } catch (Throwable) {
                // Cache is optional for this fallback.
            }

            return $providers;
        } catch (Throwable $exception) {
            Log::warning('bo3.gg odds provider request failed.', ['type' => $exception::class]);

            return [];
        }
    }

    private function matchSlug(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! preg_match('~/matches/([^/]+)$~', rtrim($path, '/'), $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    private function similarity(string $left, string $right): float
    {
        $left = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($left)) ?? '';
        $right = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($right)) ?? '';

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percentage);

        return $percentage / 100;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.bo3.base_url'), '/');
    }
}
