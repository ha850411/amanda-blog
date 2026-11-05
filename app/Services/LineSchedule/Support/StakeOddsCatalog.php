<?php

namespace App\Services\LineSchedule\Support;

use App\Support\ChineseConverter;

class StakeOddsCatalog
{
    public const GAMES = [
        'lol' => ['label' => 'LoL', 'sport' => 'league-of-legends'],
        'cs' => ['label' => 'CS2', 'sport' => 'counter-strike'],
        'val' => ['label' => 'VAL', 'sport' => 'valorant'],
        'mlb' => ['label' => 'MLB', 'sport' => 'baseball', 'category' => 'usa', 'tournament' => 'mlb'],
        'nba' => ['label' => 'NBA', 'sport' => 'basketball', 'category' => 'usa', 'tournament' => 'nba'],
    ];

    public static function game(string $value): ?string
    {
        $value = match (mb_strtolower($value)) {
            'cs2', 'counter-strike' => 'cs',
            'valorant' => 'val',
            'league-of-legends' => 'lol',
            default => mb_strtolower($value),
        };

        return isset(self::GAMES[$value]) ? $value : null;
    }

    public static function fixtureGame(array $fixture): ?string
    {
        $tournament = $fixture['tournament'] ?? [];
        foreach (self::GAMES as $game => $definition) {
            if (($tournament['category']['sport']['slug'] ?? '') === $definition['sport']
                && (! isset($definition['category']) || ($tournament['category']['slug'] ?? '') === $definition['category'])
                && (! isset($definition['tournament']) || ($tournament['slug'] ?? '') === $definition['tournament'])) {
                return $game;
            }
        }

        return null;
    }

    /** Exact aliases only: never merge a main roster with its academy or women's team. */
    public static function aliases(string $name, string $game): array
    {
        if (! in_array($game, ['nba', 'mlb'], true)) {
            return array_values(array_unique(array_map(self::normalize(...), TeamAbbreviation::aliases($name))));
        }
        $key = self::normalize($name);
        $aliases = [$key];
        foreach (config('odds_teams.'.$game, []) as $english => $shortNames) {
            $names = array_map(self::normalize(...), [$english, config('sports_labels.teams')[$english] ?? $english, ...$shortNames]);
            if (in_array($key, $names, true)) {
                $aliases = [...$aliases, ...$names];
            }
        }

        return array_values(array_unique($aliases));
    }

    public static function normalize(string $name): string
    {
        $name = ChineseConverter::toTraditional($name);

        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name) ?? $name);
    }

    public static function isKnownTeam(string $name): bool
    {
        foreach (['lol', 'nba', 'mlb'] as $game) {
            if (count(self::aliases($name, $game)) > 1) {
                return true;
            }
        }

        return false;
    }
}
