<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class LineScheduleBot
{
    private const GAME_LABELS = [
        'cs' => 'CS2',
        'valorant' => 'VALORANT',
        'lol' => 'LoL',
    ];

    public function __construct(private readonly Bo3ScheduleService $schedules) {}

    public function respond(string $message): string
    {
        $game = $this->detectGame($message);

        if ($game === null) {
            return $this->help();
        }

        try {
            $date = $this->detectDate($message);
            $matches = $this->schedules->forDate($game, $date);
        } catch (Throwable $exception) {
            report($exception);

            return '目前無法取得 bo3.gg 賽程，請稍後再試。';
        }

        $label = self::GAME_LABELS[$game];
        $dateLabel = $date->format('m/d');

        if ($matches === []) {
            return "{$label} {$dateLabel} 查無賽程。\n資料來源：bo3.gg";
        }

        $lines = ["{$label} {$dateLabel} 賽程（台灣時間）"];

        foreach (array_slice($matches, 0, 10) as $match) {
            $lines[] = sprintf(
                "\n%s｜%s\n%s",
                $match['start_at']->format('H:i'),
                $match['name'],
                $match['url'],
            );
        }

        if (count($matches) > 10) {
            $lines[] = sprintf("\n另有 %d 場，請至 bo3.gg 查看。", count($matches) - 10);
        }

        return implode("\n", $lines);
    }

    private function detectGame(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));

        if (preg_match('/(?:valorant|特戰英豪|瓦羅蘭特)/u', $normalized)) {
            return 'valorant';
        }

        if (preg_match('/(?:英雄聯盟|league of legends|\blol\b)/u', $normalized)) {
            return 'lol';
        }

        if (preg_match('/(?:counter[ -]?strike|絕對武力|\bcs2?\b)/u', $normalized)) {
            return 'cs';
        }

        return null;
    }

    private function detectDate(string $message): CarbonImmutable
    {
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/u', $message, $matches)) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $matches[1], $timezone);
        }

        if (str_contains($message, '後天')) {
            return $today->addDays(2);
        }

        if (str_contains($message, '明天') || str_contains(mb_strtolower($message), 'tomorrow')) {
            return $today->addDay();
        }

        return $today;
    }

    private function help(): string
    {
        return "請輸入遊戲與日期查詢 bo3.gg 賽程。\n例如：\n・lol 今天\n・valorant 明天\n・cs2 2026-08-12";
    }
}
