<?php

namespace App\Http\Controllers;

use App\Services\MlbWebcastService;
use App\Services\SportsPlayerService;
use App\Services\SportsScheduleService;
use App\Services\StreameastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SportsController extends Controller
{
    public function index(Request $request, SportsScheduleService $sports)
    {
        $filters = $request->validate([
            'sport' => ['nullable', 'string', 'max:60'],
            'league' => ['nullable', Rule::in(['MLB', 'NBA'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['upcoming', 'started'])],
            'range' => ['nullable', Rule::in(['current', 'history'])],
            'watch' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_-]{1,240}$/D'],
        ]);
        $initialEvent = $filters['watch'] ?? null;
        unset($filters['watch']);
        $schedule = $sports->schedule();
        $all = collect($schedule['data']);
        $now = CarbonImmutable::now(config('sports.timezone'));
        $today = $now->format('Y-m-d');
        $filters['range'] = $filters['range'] ?? 'current';
        // Apply the calendar boundary per request, even when the feed is cached.
        // An explicit date can also select a historical day directly.
        $scoped = $all->filter(fn ($match) => ! empty($filters['date'])
            ? $match['date'] === $filters['date']
            : ($filters['range'] === 'history' ? $match['date'] < $today : $match['date'] >= $today));
        $categories = $all->groupBy('category')->map(fn ($matches, $category) => [
            'label' => $matches->first()['category_label'], 'count' => $scoped->where('category', $category)->count(),
        ])->sortBy(fn ($value, $key) => array_search($key, array_keys(config('sports.categories')), true) ?: 0);
        $matches = $scoped->filter(function ($match) use ($filters, $now) {
            return (empty($filters['sport']) || $match['category'] === $filters['sport'])
                && (empty($filters['league']) || $match['league'] === $filters['league'])
                && (empty($filters['q']) || mb_stripos($match['title'].' '.$match['translated_title'], trim($filters['q'])) !== false)
                && (empty($filters['status']) || ($filters['status'] === 'upcoming'
                    ? CarbonImmutable::parse($match['start_at'])->isAfter($now)
                    : ! CarbonImmutable::parse($match['start_at'])->isAfter($now)));
        });
        $days = $matches->groupBy('date');
        $days = $filters['range'] === 'history' ? $days->sortKeysDesc() : $days->sortKeys();

        return response()->view('sports.index', [
            'schedule' => $schedule, 'matches' => $days,
            'initialEvent' => $initialEvent,
            'count' => $matches->count(), 'total' => $scoped->count(),
            'categories' => $categories, 'filters' => $filters, 'now' => $now,
            'sportCount' => $scoped->pluck('category')->unique()->count(),
            'rangeCounts' => [
                'current' => $all->where('date', '>=', $today)->count(),
                'history' => $all->where('date', '<', $today)->count(),
            ],
            'leagueCounts' => ['MLB' => $scoped->where('league', 'MLB')->count(), 'NBA' => $scoped->where('league', 'NBA')->count()],
        ], $schedule['unavailable'] ? 503 : 200)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function show(string $id, Request $request, SportsScheduleService $sports, StreameastService $streameast, SportsPlayerService $players, MlbWebcastService $mlbwebcast)
    {
        $schedule = $sports->schedule();
        if ($schedule['unavailable']) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '目前無法取得賽程，請稍後重試。'], 503);
            }

            return response()->view('sports.unavailable', [], 503)->header('X-Robots-Tag', 'noindex, nofollow');
        }
        $match = collect($schedule['data'])->firstWhere('id', $id);
        abort_if(! $match, 404);

        $streams = $sports->streams($match);
        $east = $streameast->streams($match);
        $webcast = $mlbwebcast->streams($match);
        $streams['data'] = array_merge($streams['data'], $east['data'], $webcast['data']);
        $streams['stale'] = $streams['stale'] || $east['stale'];
        foreach ($streams['data'] as &$stream) {
            $token = $players->register($match, $stream);
            $stream['player_url'] = $token ? route('sports.player', $token) : null;
            $player = $token ? $players->find($token) : null;
            $stream['playback'] = ($player['mode'] ?? '') === 'hls' ? [
                'url' => route('sports.hls.playlist', $token, false),
                'adapter' => $player['media_adapter'],
            ] : null;
        }
        unset($stream);

        if ($request->expectsJson()) {
            return response()->json([
                'match' => collect($match)->only(['id', 'translated_title', 'title', 'league', 'start_at', 'date', 'time']),
                'streams' => $streams['data'],
                'stale' => $streams['stale'],
                'warnings' => array_values(array_filter([
                    $streams['stale'] ? '部分來源暫時無法更新，目前顯示最近取得的連結。' : null,
                    $streams['failed_sources'] ? '部分轉播平台暫時無法取得來源。' : null,
                    $east['unavailable'] ? 'Streameast 暫時無法取得來源。' : null,
                    $webcast['unavailable'] ? 'MLBWebcast 暫時無法取得來源。' : null,
                ])),
            ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()->view('sports.show', [
            'match' => $match, 'streams' => $streams, 'east' => $east, 'webcast' => $webcast,
            'schedule' => $schedule, 'now' => CarbonImmutable::now(config('sports.timezone')),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
