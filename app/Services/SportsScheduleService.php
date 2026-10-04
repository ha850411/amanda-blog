<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SportsScheduleService
{
    public function schedule(): array
    {
        return $this->fetchMany(['schedule' => '/matches/all'], fn (array $rows) => $this->normalizeMatches($rows))['schedule'];
    }

    public function streams(array $match): array
    {
        $paths = [];
        foreach ($match['sources'] as $source) {
            $key = $source['source'].'/'.$source['id'];
            $paths[$key] = '/stream/'.rawurlencode($source['source']).'/'.rawurlencode($source['id']);
        }

        $results = $this->fetchMany($paths, fn (array $rows) => $this->normalizeStreams($rows));
        $streams = [];
        $failed = [];
        $stale = false;
        $updated = [];
        foreach ($results as $key => $result) {
            $provider = explode('/', $key, 2)[0];
            if ($result['unavailable']) {
                $failed[] = $provider;
            }
            $stale = $stale || $result['stale'];
            if ($result['updated_at']) {
                $updated[] = $result['updated_at'];
            }
            foreach ($result['data'] as $stream) {
                $stream['provider'] = $provider;
                $stream['platform'] = 'Sportsurge';
                $streams[$stream['url']] ??= $stream;
            }
        }

        return [
            'data' => array_values($streams),
            'failed_sources' => array_values(array_unique($failed)),
            'stale' => $stale,
            'updated_at' => $updated ? min($updated) : null,
        ];
    }

    /** Fetch only fixed upstream API paths, with bounded concurrency and a last-good cache. */
    private function fetchMany(array $paths, callable $normalize): array
    {
        $now = CarbonImmutable::now()->timestamp;
        $freshSeconds = (int) config('sports.cache_seconds', 60);
        $staleSeconds = (int) config('sports.stale_seconds', 900);
        $results = $pending = $cached = $keys = [];
        foreach ($paths as $name => $path) {
            $keys[$name] = 'sports:v3:'.hash('sha256', config('sports.api_url').$path);
            $cached[$name] = Cache::get($keys[$name]);
            if ($cached[$name] && $now - $cached[$name]['timestamp'] < $freshSeconds) {
                $results[$name] = $this->result($cached[$name]);
            } elseif (Cache::has($keys[$name].':backoff')) {
                $results[$name] = $this->fallback($cached[$name], $now, $staleSeconds);
            } else {
                $pending[$name] = $path;
            }
        }

        if ($pending === []) {
            return $results;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending) {
                foreach ($pending as $name => $path) {
                    $pool->as($name)->acceptJson()->connectTimeout(3)
                        ->timeout((int) config('sports.timeout_seconds', 8))
                        ->withoutRedirecting()
                        ->get(rtrim(config('sports.api_url'), '/').$path);
                }
            }, concurrency: 5);
        } catch (Throwable) {
            $responses = [];
        }

        foreach ($pending as $name => $path) {
            try {
                $response = $responses[$name] ?? null;
                if (! $response instanceof Response || ! $response->successful()) {
                    throw new RuntimeException('Sports feed unavailable.');
                }
                // Decoding without associative arrays distinguishes [] from {}.
                if (! is_array(json_decode($response->body()))) {
                    throw new RuntimeException('Unexpected sports feed structure.');
                }
                $data = $normalize($response->json());
                $entry = ['data' => $data, 'timestamp' => $now];
                Cache::put($keys[$name], $entry, $staleSeconds);
                Cache::forget($keys[$name].':backoff');
                $results[$name] = $this->result($entry);
            } catch (Throwable) {
                Cache::put($keys[$name].':backoff', true, 15);
                $results[$name] = $this->fallback($cached[$name], $now, $staleSeconds);
            }
        }

        return $results;
    }

    private function result(array $entry, bool $stale = false): array
    {
        return [
            'data' => $entry['data'],
            'updated_at' => CarbonImmutable::createFromTimestampUTC($entry['timestamp'])->toIso8601String(),
            'stale' => $stale,
            'unavailable' => false,
        ];
    }

    private function fallback(?array $entry, int $now, int $maxAge): array
    {
        if ($entry && $now - $entry['timestamp'] < $maxAge) {
            return $this->result($entry, true);
        }

        return ['data' => [], 'updated_at' => null, 'stale' => false, 'unavailable' => true];
    }

    private function normalizeMatches(array $rows): array
    {
        $matches = [];
        $recognized = false;
        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->identifier($row['id'] ?? null)
                || ! is_string($row['title'] ?? null) || trim($row['title']) === ''
                || ! is_numeric($row['date'] ?? null)) {
                continue;
            }
            $recognized = true;
            // The feed includes permanent channel advertisements with date=0.
            if ((float) $row['date'] < 946684800000 || (float) $row['date'] > 4102444800000) {
                continue;
            }
            $start = CarbonImmutable::createFromTimestampMsUTC($row['date'])
                ->setTimezone(config('sports.timezone'));
            $category = is_string($row['category'] ?? null) && preg_match('/^[a-z0-9-]{1,60}$/D', $row['category'])
                ? $row['category'] : 'other';
            $sources = [];
            foreach (is_array($row['sources'] ?? null) ? $row['sources'] : [] as $source) {
                if (is_array($source) && $this->identifier($source['source'] ?? null)
                    && $this->identifier($source['id'] ?? null)) {
                    $sources[$source['source'].'/'.$source['id']] = [
                        'source' => $source['source'], 'id' => $source['id'],
                    ];
                }
            }
            $teams = $this->teams($row);
            $translatedTitle = $teams
                ? implode(' 對 ', array_column($teams, 'label'))
                : str_ireplace(array_keys(config('sports_labels.events')), array_values(config('sports_labels.events')), $row['title']);
            $matches[$row['id']] = [
                'id' => $row['id'],
                'title' => mb_substr(trim($row['title']), 0, 300),
                'translated_title' => mb_substr($translatedTitle, 0, 300),
                'teams' => $teams,
                'category' => $category,
                'category_label' => config('sports.categories.'.$category, $category),
                'league' => $this->league($row, $category),
                'start_at' => $start->toIso8601String(),
                'date' => $start->format('Y-m-d'),
                'time' => $start->format('H:i'),
                'sources' => array_slice(array_values($sources), 0, 20),
                'source_url' => config('sports.source_page').'?id='.rawurlencode($row['id']),
            ];
        }
        if ($rows !== [] && ! $recognized) {
            throw new RuntimeException('Unrecognized sports schedule.');
        }
        $matches = array_values($matches);
        usort($matches, fn ($a, $b) => [$a['start_at'], $a['title']] <=> [$b['start_at'], $b['title']]);

        return $matches;
    }

    private function league(array $row, string $category): ?string
    {
        $league = match ($category) {
            'baseball' => 'MLB',
            'basketball' => 'NBA',
            default => null,
        };
        if (! $league) {
            return null;
        }
        $home = data_get($row, 'teams.home.name');
        $away = data_get($row, 'teams.away.name');
        if (! is_string($home) || ! is_string($away)) {
            $teams = preg_split('/\s+(?:vs\.?|v\.?|at|-)\s+/i', $row['title']);
            [$home, $away] = count($teams) === 2 ? $teams : ['', ''];
        }
        $normalize = fn ($name) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $known = array_map($normalize, config('sports.league_teams.'.$league, []));

        return in_array($normalize($home), $known, true) && in_array($normalize($away), $known, true) ? $league : null;
    }

    private function normalizeStreams(array $rows): array
    {
        $streams = [];
        foreach ($rows as $row) {
            $url = is_array($row) ? ($row['embedUrl'] ?? null) : null;
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https'
                || ! in_array(strtolower($parts['host'] ?? ''), config('sports.embed_hosts', []), true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== 443)) {
                continue;
            }
            $streams[$url] = [
                'url' => $url,
                'language' => is_string($row['language'] ?? null) ? mb_substr($row['language'], 0, 80) : '未標示語言',
                'hd' => ($row['hd'] ?? false) === true,
                'number' => is_numeric($row['streamNo'] ?? null) ? (int) $row['streamNo'] : count($streams) + 1,
            ];
        }
        if ($rows !== [] && $streams === []) {
            throw new RuntimeException('No supported stream URLs in response.');
        }

        return array_values($streams);
    }

    private function teams(array $row): array
    {
        $names = preg_split('/\s+(?:vs\.?|v\.?|at|-)\s+/i', $row['title']);
        $labels = array_change_key_case(config('sports_labels.teams'), CASE_LOWER);
        $teams = [];
        foreach (['home', 'away'] as $index => $side) {
            $name = data_get($row, 'teams.'.$side.'.name');
            if (! is_string($name) || trim($name) === '') {
                $name = count($names) === 2 ? $names[$index] : null;
            }
            if (! $name) {
                return [];
            }
            $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 150);
            $badge = data_get($row, 'teams.'.$side.'.badge');
            $badgeUrl = is_string($badge) && preg_match('/^[a-zA-Z0-9+\/_=-]{1,2000}$/D', $badge)
                ? rtrim(config('sports.api_url'), '/').'/images/badge/'.rawurlencode($badge).'.webp' : null;
            $fallback = config('sports_badges')[mb_strtolower($name)] ?? null;
            $initials = implode('', array_map(fn ($word) => mb_substr($word, 0, 1), preg_split('/\s+/', $name)));
            $teams[] = [
                'name' => $name, 'label' => $labels[mb_strtolower($name)] ?? $name,
                'badge' => $badgeUrl ?? $fallback, 'badge_fallback' => $fallback,
                'initials' => mb_strtoupper(mb_substr($initials, 0, 3)),
            ];
        }

        return $teams;
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,240}$/D', $value) === 1;
    }
}
