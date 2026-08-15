<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Normalizer;
use Throwable;

class LineScheduleBot
{
    private const GAME_LABELS = [
        'cs' => 'CS2',
        'valorant' => 'VALORANT',
        'lol' => 'LoL',
    ];

    public function __construct(
        private readonly Bo3ScheduleService $schedules,
        private readonly OddsApiService $odds,
        private readonly Bo3OddsService $bo3Odds,
        private readonly Bo3HeadToHeadService $headToHead,
    ) {}

    public function respond(string $message): ?string
    {
        return $this->reply($message)?->text;
    }

    public function reply(string $message): ?LineBotReply
    {
        $message = $this->normalizeCommand($message);

        if (mb_strtolower($message) === '!help') {
            return new LineBotReply($this->help());
        }

        $command = $this->parseCommand($message);

        if ($command === null) {
            return null;
        }

        $allMatches = [];

        try {
            foreach ($command['games'] as $game) {
                $gameMatches = $this->schedules->forDate(
                    $game,
                    $command['date'],
                    $command['tiers'],
                );

                foreach ($gameMatches as $match) {
                    $match['game'] = $game;
                    $match['game_label'] = self::GAME_LABELS[$game] ?? mb_strtoupper($game);
                    $allMatches[] = $match;
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            return new LineBotReply('目前無法取得 bo3.gg 賽程，請稍後再試。');
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $now = CarbonImmutable::now($timezone);

        if ($command['date']->isSameDay($now)) {
            $allMatches = array_values(array_filter(
                $allMatches,
                fn (array $match): bool => ($match['is_live'] ?? false)
                    || $match['start_at']->greaterThan($now),
            ));
        }

        if ($command['team'] !== null) {
            $allMatches = array_values(array_filter(
                $allMatches,
                fn (array $match): bool => str_contains(
                    mb_strtolower($match['name']),
                    mb_strtolower($command['team']),
                ),
            ));
        }

        if (count($command['games']) > 1) {
            $gamePriority = array_flip($command['games']);
            usort($allMatches, function (array $a, array $b) use ($gamePriority): int {
                $cmp = $a['start_at'] <=> $b['start_at'];

                if ($cmp !== 0) {
                    return $cmp;
                }

                return ($gamePriority[$a['game'] ?? ''] ?? 99) <=> ($gamePriority[$b['game'] ?? ''] ?? 99);
            });
        }

        $isMultiGame = count($command['games']) > 1;
        $dateLabel = $command['date']->format('m/d');
        $tierLabel = $command['tiers'] === []
            ? '全部 Tier'
            : implode('/', array_map('mb_strtoupper', $command['tiers'])).' Tier';

        if ($isMultiGame) {
            $gameNames = implode('/', array_map(
                fn (string $g): string => self::GAME_LABELS[$g] ?? mb_strtoupper($g),
                $command['games'],
            ));
            $label = "綜合賽程（{$gameNames}）";
            $imageTitle = "綜合賽程｜{$dateLabel}｜{$tierLabel}";
            $filteredUrl = $this->multiGameFilteredUrl($command['date'], $command['tiers']);
        } else {
            $singleGame = $command['games'][0];
            $label = self::GAME_LABELS[$singleGame];
            $imageTitle = "{$label}｜{$dateLabel}｜{$tierLabel}";
            $filteredUrl = $this->schedules->filteredUrl(
                $singleGame,
                $command['date'],
                $command['tiers'],
            );
        }

        if ($allMatches === []) {
            $noMatchLabel = $isMultiGame ? '綜合賽程' : $label;

            return new LineBotReply(
                "{$noMatchLabel} {$dateLabel} 查無賽程。\n完整賽程｜{$filteredUrl}",
                $filteredUrl,
            );
        }

        $visibleMatches = array_slice($allMatches, 0, $command['limit']);
        $visibleMatches = $this->headToHead->enrich($visibleMatches);
        $visibleMatches = $this->odds->enrich($visibleMatches, $command['date']);
        $visibleMatches = $this->bo3Odds->enrichMissing($visibleMatches);
        $lines = [
            "{$label}｜{$dateLabel}｜{$tierLabel}",
            '時間基準｜台灣時間',
        ];

        foreach ($visibleMatches as $index => $match) {
            $lines[] = "\n──────────";
            $gameTag = $isMultiGame ? sprintf('【%s】', $match['game_label'] ?? '') : '';
            $liveTag = ($match['is_live'] ?? false) ? '【滾球】' : '';
            $lines[] = sprintf(
                "第 %d 場%s%s｜%s｜%s\n%s\nvs\n%s\n\n賽事｜%s",
                $index + 1,
                $gameTag,
                $liveTag,
                $match['start_at']->format('H:i'),
                $match['format'],
                $match['team1'],
                $match['team2'],
                $match['tournament'],
            );

            if ($match['is_live'] ?? false) {
                $seriesScore = $match['series_score'] ?? null;
                $mapScore = $match['score'] ?? null;

                if ($seriesScore !== null && $mapScore !== null && $seriesScore !== $mapScore) {
                    $lines[] = sprintf('目前比分｜%s（當局 %s）', $seriesScore, $mapScore);
                } elseif ($seriesScore !== null) {
                    $lines[] = '目前比分｜'.$seriesScore;
                } elseif ($mapScore !== null) {
                    $lines[] = '目前比分｜'.$mapScore;
                }
            }

            if ($match['odds'] === null) {
                $lines[] = '獨贏賠率｜暫無盤口';
            } else {
                $lines[] = sprintf(
                    "獨贏賠率｜\n%s　%.2f（%s）\n%s　%.2f（%s）",
                    $match['team1'],
                    $match['odds']['team1']['price'],
                    $match['odds']['team1']['bookmaker'],
                    $match['team2'],
                    $match['odds']['team2']['price'],
                    $match['odds']['team2']['bookmaker'],
                );
            }

            if (($match['h2h'] ?? null) !== null) {
                $h2h = $match['h2h'];
                $lines[] = sprintf(
                    '近期交手｜%s %d 勝・%s %d 勝（近 %d 場，小局 %d：%d）',
                    $match['team1'],
                    $h2h['team1_wins'],
                    $match['team2'],
                    $h2h['team2_wins'],
                    $h2h['sample_size'],
                    $h2h['team1_games'],
                    $h2h['team2_games'],
                );

                $seriesList = array_slice(is_array($h2h['series'] ?? null) ? $h2h['series'] : [], 0, 5);
                if ($seriesList !== []) {
                    $lines[] = '交手明細｜';
                    foreach ($seriesList as $item) {
                        $winnerTeam = ($item['winner'] ?? null) === 'team1' ? $match['team1'] : $match['team2'];
                        $lines[] = sprintf(
                            '・%s %s  %d：%d（%s 勝）',
                            $item['date'] ?? '—',
                            $item['format'] ?? 'BO?',
                            $item['team1_score'] ?? 0,
                            $item['team2_score'] ?? 0,
                            $winnerTeam,
                        );
                    }
                }
            }
        }

        if (count($allMatches) > $command['limit']) {
            $lines[] = sprintf("\n另有 %d 場，請至 bo3.gg 查看。", count($allMatches) - $command['limit']);
        }

        $lines[] = "\n完整賽程｜{$filteredUrl}";

        return new LineBotReply(
            implode("\n", $lines),
            $filteredUrl,
            [
                'title' => $imageTitle,
                'subtitle' => '台灣時間｜'.count($visibleMatches).' 場賽程',
                'game' => $isMultiGame ? 'all' : $command['games'][0],
                'matches' => array_map(
                    fn (array $match): array => [
                        'game' => $match['game'] ?? ($command['games'][0] ?? null),
                        'start_time' => $match['start_at']->format('H:i'),
                        'format' => $match['format'],
                        'is_live' => $match['is_live'] ?? false,
                        'series_score' => $match['series_score'] ?? null,
                        'score' => $match['score'] ?? null,
                        'team1' => $match['team1'],
                        'team2' => $match['team2'],
                        'tournament' => $match['tournament'],
                        'odds' => $match['odds'],
                        'h2h' => $match['h2h'] ?? null,
                    ],
                    $visibleMatches,
                ),
            ],
        );
    }

    /**
     * @return array{games: array<int, string>, date: CarbonImmutable, tiers: array<int, string>, limit: int, team: ?string}|null
     */
    private function parseCommand(string $message): ?array
    {
        if (! preg_match('/^!(賽程|schedule|match|matches|lol|val|cs)(?:\s+(今天|明天|後天|\d{1,2}\/\d{1,2}))?(?:\s+(.*))?$/iu', $message, $matches)) {
            return null;
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $date = isset($matches[2]) && $matches[2] !== ''
            ? $this->parseDate($matches[2], $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();

        if ($date === null) {
            return null;
        }

        $options = $this->parseOptions($matches[3] ?? '');

        if ($options === null) {
            return null;
        }

        $commandKey = mb_strtolower($matches[1]);

        if (in_array($commandKey, ['賽程', 'schedule', 'match', 'matches'], true)) {
            $games = $options['games'] ?? ['lol', 'valorant', 'cs'];
        } else {
            $defaultGame = ['lol' => 'lol', 'val' => 'valorant', 'cs' => 'cs'][$commandKey];
            $games = $options['games'] ?? [$defaultGame];
        }

        return [
            'games' => $games,
            'date' => $date,
            'tiers' => $options['tiers'],
            'limit' => $options['limit'],
            'team' => $options['team'],
        ];
    }

    private function normalizeCommand(string $message): string
    {
        // LINE clients and input methods may insert invisible formatting marks,
        // or send full-width punctuation that renders like the documented ASCII
        // command. Normalize those differences before matching the command.
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($message, Normalizer::FORM_KC);

            if (is_string($normalized)) {
                $message = $normalized;
            }
        }

        $message = preg_replace('/\p{Cf}+/u', '', $message) ?? $message;
        $message = preg_replace('/[\p{Z}\s]+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    private function parseDate(string $value, string $timezone): ?CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($value === '今天') {
            return $today;
        }

        if ($value === '明天') {
            return $today->addDay();
        }

        if ($value === '後天') {
            return $today->addDays(2);
        }

        $date = CarbonImmutable::createFromFormat('!Y/m/d', "{$today->year}/{$value}", $timezone);
        $dateErrors = CarbonImmutable::getLastErrors();

        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    /**
     * @return array{games?: array<int, string>, tiers: array<int, string>, limit: int, team: ?string}|null
     */
    private function parseOptions(string $input): ?array
    {
        // A typical combined S-tier day can exceed ten matches. Keep the
        // default large enough for the full image while retaining an explicit
        // limit option for callers that want a shorter response.
        $options = ['tiers' => ['s'], 'limit' => 19, 'team' => null];

        if (trim($input) === '') {
            return $options;
        }

        preg_match_all('/(game|tier|limit|team)=(?:"([^"]+)"|(\S+))/iu', $input, $optionMatches, PREG_SET_ORDER);
        $consumed = trim((string) preg_replace('/(game|tier|limit|team)=(?:"[^"]+"|\S+)/iu', '', $input));

        if ($consumed !== '') {
            return null;
        }

        foreach ($optionMatches as $option) {
            $key = mb_strtolower($option[1]);
            $value = trim($option[2] !== '' ? $option[2] : $option[3]);

            if ($key === 'game') {
                $rawGames = array_values(array_filter(preg_split('/[,\\/]+/', mb_strtolower($value)) ?: []));
                $gameMap = [
                    'lol' => 'lol',
                    'val' => 'valorant',
                    'valorant' => 'valorant',
                    'cs' => 'cs',
                    'cs2' => 'cs',
                ];

                $games = [];

                foreach ($rawGames as $rawGame) {
                    if (! isset($gameMap[$rawGame])) {
                        return null;
                    }

                    $games[] = $gameMap[$rawGame];
                }

                $games = array_values(array_unique($games));

                if ($games === []) {
                    return null;
                }

                $options['games'] = $games;
            } elseif ($key === 'tier') {
                if (mb_strtolower($value) === 'all') {
                    $options['tiers'] = [];

                    continue;
                }

                $tiers = array_values(array_unique(array_filter(array_map(
                    fn (string $tier): string => mb_strtolower(trim($tier)),
                    explode(',', $value),
                ))));

                if ($tiers === [] || array_diff($tiers, ['s', 'a', 'b', 'c', 'd']) !== []) {
                    return null;
                }

                $options['tiers'] = $tiers;
            } elseif ($key === 'limit') {
                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 19) {
                    return null;
                }

                $options['limit'] = (int) $value;
            } elseif ($value === '') {
                return null;
            } else {
                $options['team'] = $value;
            }
        }

        return $options;
    }

    private function multiGameFilteredUrl(CarbonImmutable $date, array $tiers): string
    {
        $url = rtrim((string) config('services.bo3.base_url', 'https://bo3.gg'), '/').'/matches/current?';
        $query = [];

        if ($tiers !== []) {
            $query[] = 'tiers='.implode(',', array_map('rawurlencode', $tiers));
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');

        if ($date->isSameDay(CarbonImmutable::now($timezone))) {
            $query[] = 'period';
        } else {
            $query[] = 'date='.$date->format('Y-m-d');
        }

        return $url.implode('&', $query);
    }

    private function help(): string
    {
        return "指令格式：\n!match｜!lol｜!val｜!cs（未填日期預設今天）\n!賽程 08/15 game=lol/val/cs\n!lol 今天｜!val 明天｜!cs 08/11\n\n查今天顯示滾球中和尚未開打的賽事，預設查 S Tier。\n可選參數：game=lol/val/cs｜tier=s,a｜tier=all｜limit=5｜team=G2";
    }
}
