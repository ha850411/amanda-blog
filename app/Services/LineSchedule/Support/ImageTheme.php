<?php

namespace App\Services\LineSchedule\Support;

class ImageTheme
{
    /** @var array<int|string, array{bg: string, border: string, text: string}> */
    public const FORMAT_THEMES = [
        1 => [
            'bg' => '#082f49',
            'border' => '#0284c7',
            'text' => '#38bdf8',
        ],
        2 => [
            'bg' => '#042f2e',
            'border' => '#0d9488',
            'text' => '#2dd4bf',
        ],
        3 => [
            'bg' => '#064e3b',
            'border' => '#059669',
            'text' => '#6ee7b7',
        ],
        5 => [
            'bg' => '#451a03',
            'border' => '#f59e0b',
            'text' => '#fde047',
        ],
        7 => [
            'bg' => '#3b0764',
            'border' => '#c084fc',
            'text' => '#f5d0fe',
        ],
        'default' => [
            'bg' => '#1e293b',
            'border' => '#475569',
            'text' => '#94a3b8',
        ],
    ];

    /** @var array<string, array{accent: string, badge_bg: string, badge_text: string, card_border: string, label: string}> */
    public const GAME_THEMES = [
        'mlb' => [
            'accent' => '#0284c7',
            'badge_bg' => '#08223c',
            'badge_text' => '#7dd3fc',
            'card_border' => '#142942',
            'label' => 'MLB',
        ],
        'cs' => [
            'accent' => '#f59e0b',
            'badge_bg' => '#281404',
            'badge_text' => '#fde68a',
            'card_border' => '#32200e',
            'label' => 'CS2',
        ],
        'valorant' => [
            'accent' => '#ff4655',
            'badge_bg' => '#2b0b14',
            'badge_text' => '#fecdd3',
            'card_border' => '#35141d',
            'label' => 'VAL',
        ],
        'lol' => [
            'accent' => '#c89b3c',
            'badge_bg' => '#1c1608',
            'badge_text' => '#fef08a',
            'card_border' => '#2d2616',
            'label' => 'LoL',
        ],
        'default' => [
            'accent' => '#3b82f6',
            'badge_bg' => '#172554',
            'badge_text' => '#bfdbfe',
            'card_border' => '#1b2a44',
            'label' => 'MATCH',
        ],
    ];

    /**
     * @return array{accent: string, badge_bg: string, badge_text: string, card_border: string, label: string}
     */
    public static function forGame(?string $game): array
    {
        $normalized = mb_strtolower(trim((string) $game));

        return self::GAME_THEMES[$normalized] ?? self::GAME_THEMES['default'];
    }

    /**
     * @return array{bg: string, border: string, text: string, label: string}
     */
    public static function formatTheme(mixed $format): array
    {
        $raw = trim((string) $format);
        $text = mb_strtoupper($raw);
        preg_match('/(?:BO)?(\d+)/i', $text, $matches);
        $number = isset($matches[1]) ? (int) $matches[1] : null;

        $theme = self::FORMAT_THEMES[$number] ?? self::FORMAT_THEMES['default'];

        return [
            'bg' => $theme['bg'],
            'border' => $theme['border'],
            'text' => $theme['text'],
            'label' => $text !== '' ? $text : 'BO?',
        ];
    }

    /**
     * @return array{bg: string, border: string, text: string, label: string}
     */
    public static function resultTheme(string $result): array
    {
        return match ($result) {
            'W' => ['bg' => '#07382f', 'border' => '#16856b', 'text' => '#6ee7b7', 'label' => '勝'],
            'L' => ['bg' => '#3d1724', 'border' => '#9f354e', 'text' => '#fda4af', 'label' => '敗'],
            'D' => ['bg' => '#3c3016', 'border' => '#8c6b20', 'text' => '#fde68a', 'label' => '和'],
            default => ['bg' => '#182338', 'border' => '#334155', 'text' => '#94a3b8', 'label' => '—'],
        };
    }

    public static function seriesWinSlots(mixed $format): int
    {
        if (preg_match('/BO(\d+)/i', trim((string) $format), $matches) === 1) {
            $bo = (int) $matches[1];

            return max(1, intdiv($bo, 2) + 1);
        }

        return 2; // Default for BO3 or unknown
    }
}
