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
     * @return array<int, array{name: string, start_at: CarbonImmutable, url: string}>
     */
    public function forDate(string $game, CarbonImmutable $date): array
    {
        if (! isset(self::PATHS[$game])) {
            throw new RuntimeException("Unsupported game: {$game}");
        }

        $dateString = $date->format('Y-m-d');
        $cacheKey = "bo3-schedule:{$game}:{$dateString}";

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $matches = $this->fetch($game, $dateString);

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

    /**
     * @return array<int, array{name: string, start_at: CarbonImmutable, url: string}>
     */
    private function fetch(string $game, string $date): array
    {
        $response = Http::accept('text/html')
            ->withUserAgent('AmandaBlogLineBot/1.0')
            ->timeout((int) config('services.bo3.timeout_seconds', 10))
            ->retry(2, 200)
            ->get(rtrim((string) config('services.bo3.base_url'), '/').self::PATHS[$game], [
                'date' => $date,
            ]);

        $response->throw();

        if (! preg_match('/<script\b[^>]*\bid=["\']micro-markup["\'][^>]*>(.*?)<\/script>/is', $response->body(), $matches)) {
            throw new RuntimeException('bo3.gg schedule data was not found.');
        }

        $events = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');

        return collect($events)
            ->filter(fn (mixed $event): bool => is_array($event)
                && ($event['@type'] ?? null) === 'SportsEvent'
                && isset($event['name'], $event['startDate'], $event['url']))
            ->map(function (array $event) use ($timezone): array {
                $name = preg_replace('/\s+/u', ' ', (string) $event['name']);

                return [
                    'name' => trim($name ?? (string) $event['name']),
                    'start_at' => CarbonImmutable::parse($event['startDate'])->setTimezone($timezone),
                    'url' => (string) $event['url'],
                ];
            })
            ->filter(fn (array $event): bool => $event['start_at']->format('Y-m-d') === $date)
            ->sortBy('start_at')
            ->values()
            ->all();
    }
}
