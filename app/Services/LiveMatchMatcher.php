<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class LiveMatchMatcher
{
    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, array<string, mixed>>  $events
     * @param  callable(array<string, mixed>): array{home: string, away: string}  $teams
     * @return array<int, array<string, mixed>>
     */
    public function match(array $matches, array $events, callable $teams): array
    {
        $result = [];

        foreach ($matches as $index => $match) {
            if (($match['game'] ?? null) !== 'lol') {
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

        similar_text($left, $right, $percentage);

        return $percentage / 100;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($name)) ?? '';
    }
}
