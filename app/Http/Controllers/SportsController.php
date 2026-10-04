<?php

namespace App\Http\Controllers;

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
        ]);
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

    public function show(string $id, SportsScheduleService $sports, StreameastService $streameast, SportsPlayerService $players)
    {
        $schedule = $sports->schedule();
        if ($schedule['unavailable']) {
            return response()->view('sports.unavailable', [], 503)->header('X-Robots-Tag', 'noindex, nofollow');
        }
        $match = collect($schedule['data'])->firstWhere('id', $id);
        abort_if(! $match, 404);

        $streams = $sports->streams($match);
        $east = $streameast->streams($match);
        $streams['data'] = array_merge($streams['data'], $east['data']);
        $streams['stale'] = $streams['stale'] || $east['stale'];
        foreach ($streams['data'] as &$stream) {
            $token = $players->register($match, $stream);
            $stream['player_url'] = $token ? route('sports.player', $token) : null;
        }
        unset($stream);

        return response()->view('sports.show', [
            'match' => $match, 'streams' => $streams, 'east' => $east,
            'schedule' => $schedule, 'now' => CarbonImmutable::now(config('sports.timezone')),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
