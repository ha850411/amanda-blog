<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class LiveMatchMatcher
{
    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, array<string, mixed>>  $events
     * @param  callable(array<string, mixed>): array{home: string, away: string}  $teams
     * @param  array<int, string>  $supportedGames
     * @return array<int, array<string, mixed>>
     */
    public function match(
        array $matches,
        array $events,
        callable $teams,
        array $supportedGames = ['lol'],
    ): array {
        $result = [];

        foreach ($matches as $index => $match) {
            if (! in_array($match['game'] ?? null, $supportedGames, true)) {
                continue;
            }

            $best = null;
            $bestScore = 0.0;

            foreach ($events as $event) {
                $eventTeams = $teams($event);
                $home = trim($eventTeams['home'] ?? '');
                $away = trim($eventTeams['away'] ?? '');

                if ($home === '' || $away === '') {
                    continue;
                }

                if (is_string($event['date'] ?? null)) {
                    try {
                        $eventTime = CarbonImmutable::parse($event['date']);

                        if (abs($match['start_at']->utc()->diffInMinutes($eventTime, false)) > 360) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }

                $direct = ($this->similarity((string) $match['team1'], $home)
                    + $this->similarity((string) $match['team2'], $away)) / 2;
                $reverse = ($this->similarity((string) $match['team1'], $away)
                    + $this->similarity((string) $match['team2'], $home)) / 2;
                $score = max($direct, $reverse);

                if ($score >= 0.72 && $score > $bestScore) {
                    $best = $event;
                    $bestScore = $score;
                }
            }

            if ($best !== null) {
                $result[$index] = $best;
            }
        }

        return $result;
    }

    public function firstTeamUsesHome(array $match, string $home, string $away): bool
    {
        return $this->similarity((string) $match['team1'], $home)
            >= $this->similarity((string) $match['team1'], $away);
    }

    private function similarity(string $left, string $right): float
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        if (str_starts_with($left, $right) || str_starts_with($right, $left)) {
            $shorter = min(strlen($left), strlen($right));
            $longer = max(strlen($left), strlen($right));

            return max(0.85, $shorter / $longer);
        }

        similar_text($left, $right, $percentage);

        return $percentage / 100;

    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($name)) ?? '';

        $aliases = [
            'navi' => 'natusvincere',
            'mouz' => 'mousesports',
            'nip' => 'ninjasinpyjamas',
            'vp' => 'virtuspro',
            'eg' => 'evilgeniuses',
            'c9' => 'cloud9',
            '100t' => '100thieves',
            'th' => 'teamheretics',
            'kc' => 'karminecorp',
            'ts' => 'teamsecret',
            'dk' => 'dpluskia',
            'kdf' => 'kwangdongfreecs',
            'fox' => 'fearx',
            'bro' => 'brion',
            'freditbrion' => 'brion',
            'oksavingsbankbrion' => 'brion',
            'gen' => 'geng',
            'gengesports' => 'geng',
            't1esports' => 't1',
            'prx' => 'paperrex',
            'fnc' => 'fnatic',
            'tl' => 'liquid',
            'teamliquid' => 'liquid',
            'teamspirit' => 'spirit',
            'teamfalcons' => 'falcons',
            'furiaesports' => 'furia',
            'futesports' => 'fut',
            'lynnvision' => 'lynnvision',
            'lynnvisiongaming' => 'lynnvision',
            'complexitygaming' => 'complexity',
            'bigclan' => 'big',
            'fazeclan' => 'faze',
            'g2esports' => 'g2',
            'imperialesports' => 'imperial',
            'paingaming' => 'pain',
            'bleed' => 'bleedesports',
            'betboomteam' => 'betboom',
            'nemigagaming' => 'nemiga',
            'amkalesports' => 'amkal',
            'wildcardgaming' => 'wildcard',
            'sangalesports' => 'sangal',
            'nordicpartnersgaming' => 'nordicpartners',
            'innercircleesports' => 'innercircle',
        ];

        return $aliases[$normalized] ?? $normalized;
    }
}
