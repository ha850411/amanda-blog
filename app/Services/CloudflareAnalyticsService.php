<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareAnalyticsService
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    private const GROUP_LIMIT = 10000;

    public function configured(): bool
    {
        return trim((string) config('cloudflare.api_token')) !== ''
            && preg_match('/\A[a-f0-9]{32}\z/i', (string) config('cloudflare.account_id')) === 1
            && (empty(config('cloudflare.site_tag'))
                || preg_match('/\A[a-f0-9]{32}\z/i', (string) config('cloudflare.site_tag')) === 1);
    }

    /**
     * Cloudflare's RUM count is already the estimated page-view count.
     * Never multiply it by sampleInterval, or use CDN requests as page views.
     */
    public function fetch(CarbonImmutable $start, CarbonImmutable $end, array $articleIds): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Cloudflare analytics is not configured.');
        }

        sort($articleIds);
        $paths = [];
        foreach ($articleIds as $id) {
            $paths[] = '/article/'.$id;
            $paths[] = '/article/'.$id.'/';
        }

        $key = 'cloudflare:articles:'.hash('sha256', json_encode([
            config('cloudflare.account_id'), config('cloudflare.site_tag'),
            config('cloudflare.hostname'), config('cloudflare.api_token'),
            $start->toDateString(), $end->toDateString(), $paths,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($key, config('cloudflare.cache_seconds'), function () use ($start, $end, $paths) {
            $now = CarbonImmutable::now('Asia/Taipei');
            $until = $end->addDay()->startOfDay()->min($now);
            $base = [
                'by_article' => [],
                'daily' => [],
                'sampled' => false,
                'fetched_at' => $now->toIso8601String(),
                'through' => $until->toIso8601String(),
            ];

            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $base['daily'][$day->toDateString()] = 0;
            }

            if ($paths === []) {
                return $base;
            }

            $response = Http::withToken((string) config('cloudflare.api_token'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('cloudflare.timeout_seconds'))
                ->post(self::ENDPOINT, [
                    'query' => $this->query(),
                    'variables' => [
                        'accountTag' => config('cloudflare.account_id'),
                        'filter' => array_filter([
                            'datetime_geq' => $start->utc()->toIso8601String(),
                            'datetime_lt' => $until->utc()->toIso8601String(),
                            'requestHost' => config('cloudflare.hostname'),
                            'requestPath_in' => $paths,
                            'siteTag' => config('cloudflare.site_tag'),
                        ], fn ($value) => $value !== null && $value !== ''),
                    ],
                ]);

            // Do not include Cloudflare response bodies, request headers or tokens in exceptions.
            if (! $response->successful() || $response->json('errors')) {
                throw new RuntimeException('Cloudflare analytics query failed.');
            }

            $account = $response->json('data.viewer.accounts.0');
            if (! is_array($account)
                || ! isset($account['articles'], $account['hourly'])
                || ! is_array($account['articles']) || ! is_array($account['hourly'])) {
                throw new RuntimeException('Cloudflare analytics returned an invalid result.');
            }

            // GraphQL limits groups, not events. Never silently show truncated totals.
            if (count($account['articles']) >= self::GROUP_LIMIT || count($account['hourly']) >= self::GROUP_LIMIT) {
                throw new RuntimeException('Cloudflare analytics result is incomplete.');
            }

            foreach ($account['articles'] as $row) {
                $count = $this->pageViews($row);
                $path = $row['dimensions']['requestPath'] ?? null;
                if (! is_string($path) || ! in_array($path, $paths, true)
                    || ! preg_match('#\A/article/([1-9][0-9]*)/?\z#', $path, $matches)) {
                    throw new RuntimeException('Cloudflare analytics returned an unexpected article path.');
                }

                $id = (int) $matches[1];
                $base['by_article'][$id] = ($base['by_article'][$id] ?? 0) + $count;
                $base['sampled'] = $base['sampled'] || ($row['avg']['sampleInterval'] ?? 1) > 1;
            }

            foreach ($account['hourly'] as $row) {
                $count = $this->pageViews($row);
                $hour = $row['dimensions']['datetimeHour'] ?? null;
                if (! is_string($hour) || $hour === '') {
                    throw new RuntimeException('Cloudflare analytics returned an invalid time bucket.');
                }
                $day = CarbonImmutable::parse($hour, 'UTC')->tz('Asia/Taipei')->toDateString();
                if (! array_key_exists($day, $base['daily'])) {
                    throw new RuntimeException('Cloudflare analytics returned an unexpected time bucket.');
                }
                $base['daily'][$day] += $count;
                $base['sampled'] = $base['sampled'] || ($row['avg']['sampleInterval'] ?? 1) > 1;
            }

            return $base;
        });
    }

    private function pageViews(array $row): int
    {
        if (! isset($row['count']) || ! is_numeric($row['count']) || $row['count'] < 0) {
            throw new RuntimeException('Cloudflare analytics returned an invalid count.');
        }

        return (int) round((float) $row['count']);
    }

    private function query(): string
    {
        $limit = self::GROUP_LIMIT;

        return <<<GRAPHQL
            query ArticleAnalytics {
                viewer {
                    accounts(filter: {accountTag: \$accountTag}) {
                        articles: rumPageloadEventsAdaptiveGroups(
                            filter: \$filter, limit: {$limit}, orderBy: [count_DESC]
                        ) {
                            count
                            avg { sampleInterval }
                            dimensions { requestPath }
                        }
                        hourly: rumPageloadEventsAdaptiveGroups(
                            filter: \$filter, limit: {$limit}, orderBy: [datetimeHour_ASC]
                        ) {
                            count
                            avg { sampleInterval }
                            dimensions { datetimeHour }
                        }
                    }
                }
            }
            GRAPHQL;
    }
}
