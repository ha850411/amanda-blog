<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class RecentForm
{
    /**
     * @param  array<int, array{date: string, opponent: string, format: string, team_score: int, opponent_score: int, result: string}>  $matches
     */
    public static function fromMatches(array $matches): array
    {
        $matches = array_slice(array_values($matches), 0, 5);

        return self::summarize(array_column($matches, 'result')) + ['matches' => $matches];
    }

    public static function cutoff(array $match): CarbonImmutable
    {
        $now = CarbonImmutable::now((string) config('services.bo3.timezone', 'Asia/Taipei'));
        $start = $match['start_at'] ?? $now;

        return $start->lessThan($now) ? $start : $now;
    }

    /** Results are ordered newest first; fewer than five is an honest partial sample. */
    public static function summarize(array $results): array
    {
        $results = array_slice(array_values($results), 0, 5);

        return [
            'sample_size' => count($results),
            'wins' => count(array_filter($results, fn (string $r): bool => $r === 'W')),
            'losses' => count(array_filter($results, fn (string $r): bool => $r === 'L')),
            'draws' => count(array_filter($results, fn (string $r): bool => $r === 'D')),
            'results' => $results,
        ];
    }

    public static function label(?array $form): string
    {
        if ($form === null) {
            return '暫無資料';
        }
        if ($form['sample_size'] === 0) {
            return '無已完賽紀錄';
        }

        $label = sprintf('%d 勝 %d 敗', $form['wins'], $form['losses']);
        if ($form['draws'] > 0) {
            $label .= sprintf(' %d 和', $form['draws']);
        }

        return $label.($form['sample_size'] < 5 ? '（僅 '.$form['sample_size'].' 場）' : '');
    }
}
