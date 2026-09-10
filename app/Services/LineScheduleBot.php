<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Normalizer;
use Throwable;

class LineScheduleBot
{
    public const MAX_SCHEDULE_DAYS = 7;

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
        private readonly LolLiveScoreService $liveScores,
        private readonly ?StakeBetService $stake = null,
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

        if (preg_match('/^!(?:bet|bets|stake|投注)(?:\s+(.*))?$/iu', $message, $matches)) {
            $argument = trim($matches[1] ?? '');
            if (mb_strtolower($argument) === 'help') {
                return new LineBotReply("指令格式：\n!bet\n查詢 Stake 進行中的體育投注、即時賽況與兌現。\n\n!r 或 !record [日期或區間]\n查詢指定日期或區間之投注紀錄與損益統計圖（支援 09-06、09-01~09-06、近7天、本週等）。\n\n!bet balance\n查詢 Stake 帳號 USDT 即時資金水位。");
            }

            return ($this->stake ?? app(StakeBetService::class))->reply($argument);
        }

        if (preg_match('/^!(?:record|records|history|pnl|損益|紀錄|記錄|r)(?:\s+(.*))?$/iu', $message, $matches)) {
            $argument = trim($matches[1] ?? '');
            if (mb_strtolower($argument) === 'help') {
                return new LineBotReply("指令格式：\n!r 或 !record [日期或區間]\n查詢 Stake 投注紀錄與損益統計圖。\n\n支援範例：\n・!r（預設今天）\n・!r 昨天 或 !r 09-06 或 !r 9/6\n・!r 09-01~09-06（區間統計，最多 30 天）\n・!r 近7天 或 !r 7d（最多 30 天）\n・!r 本週、上週、本月\n・加 text 查看純文字（例如 !r 7d text）\n\n備註：\n・查詢區間最多 30 天\n・注單明細最多顯示 10 筆");
            }

            $fullArg = $argument === '' ? 'record' : 'record '.$argument;

            return ($this->stake ?? app(StakeBetService::class))->reply($fullArg);
        }

        $command = $this->parseCommand($message);

        if (is_string($command)) {
            return new LineBotReply($command);
        }

        if ($command === null) {
            return null;
        }

        $allMatches = [];

        try {
            $currentDate = $command['start_date'];
            while ($currentDate->lessThanOrEqualTo($command['end_date'])) {
                foreach ($command['games'] as $game) {
                    $gameMatches = $this->schedules->forDate(
                        $game,
                        $currentDate,
                        $command['tiers'],
                    );

                    foreach ($gameMatches as $match) {
                        $match['game'] = $game;
                        $match['game_label'] = self::GAME_LABELS[$game] ?? mb_strtoupper($game);
                        $allMatches[] = $match;
                    }
                }
                $currentDate = $currentDate->addDay();
            }
        } catch (Throwable $exception) {
            report($exception);

            return new LineBotReply('目前無法取得 bo3.gg 賽程，請稍後再試。');
        }

        $allMatches = array_values(collect($allMatches)->unique(function (array $match): string {
            return (string) ($match['url'] ?? ($match['game'] ?? '').$match['name'].$match['start_at']->toIso8601String());
        })->all());

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $now = CarbonImmutable::now($timezone);

        $includesToday = $command['start_date']->lessThanOrEqualTo($now)
            && $command['end_date']->greaterThanOrEqualTo($now->startOfDay());

        if ($includesToday) {
            $allMatches = $this->liveScores->enrich($allMatches);

            $allMatches = array_values(array_filter(
                $allMatches,
                fn (array $match): bool => ! $match['start_at']->isSameDay($now)
                    || ($match['is_live'] ?? false)
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

        $gamePriority = array_flip($command['games']);
        usort($allMatches, function (array $a, array $b) use ($gamePriority): int {
            $cmp = $a['start_at'] <=> $b['start_at'];

            if ($cmp !== 0) {
                return $cmp;
            }

            return ($gamePriority[$a['game'] ?? ''] ?? 99) <=> ($gamePriority[$b['game'] ?? ''] ?? 99);
        });

        $isMultiGame = count($command['games']) > 1;
        $dateLabel = $command['is_range']
            ? $command['start_date']->format('m/d').' ~ '.$command['end_date']->format('m/d')
            : $command['start_date']->format('m/d');

        $tierLabel = $command['tiers'] === []
            ? '全部 Tier'
            : implode('/', array_map('mb_strtoupper', $command['tiers'])).' Tier';

        $urlDate = ($command['start_date']->lessThanOrEqualTo($now) && $command['end_date']->greaterThanOrEqualTo($now->startOfDay()))
            ? $now->startOfDay()
            : $command['start_date'];

        if ($isMultiGame) {
            $gameNames = implode('/', array_map(
                fn (string $g): string => self::GAME_LABELS[$g] ?? mb_strtoupper($g),
                $command['games'],
            ));
            $label = "綜合賽程（{$gameNames}）";
            $imageTitle = "綜合賽程｜{$dateLabel}｜{$tierLabel}";
            $filteredUrl = $this->multiGameFilteredUrl($urlDate, $command['tiers']);
        } else {
            $singleGame = $command['games'][0];
            $label = self::GAME_LABELS[$singleGame];
            $imageTitle = "{$label}｜{$dateLabel}｜{$tierLabel}";
            $filteredUrl = $this->schedules->filteredUrl(
                $singleGame,
                $urlDate,
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
        $visibleMatches = $this->odds->enrich(
            $visibleMatches,
            $command['start_date'],
            $command['end_date'],
        );
        $visibleMatches = $this->bo3Odds->enrichMissing($visibleMatches);
        $lines = [
            "{$label}｜{$dateLabel}｜{$tierLabel}",
            '時間基準｜台灣時間',
        ];

        foreach ($visibleMatches as $index => $match) {
            $lines[] = "\n──────────";
            $gameTag = $isMultiGame ? sprintf('【%s】', $match['game_label'] ?? '') : '';
            $liveTag = ($match['is_live'] ?? false) ? '【滾球】' : '';
            $timeString = $command['is_range']
                ? $match['start_at']->format('m/d H:i')
                : $match['start_at']->format('H:i');

            $lines[] = sprintf(
                "第 %d 場%s%s｜%s｜%s\n%s\nvs\n%s\n\n賽事｜%s",
                $index + 1,
                $gameTag,
                $liveTag,
                $timeString,
                $match['format'],
                $match['team1'],
                $match['team2'],
                $match['tournament'],
            );

            if ($match['is_live'] ?? false) {
                $seriesScore = $match['series_score'] ?? null;
                $mapScore = $match['score'] ?? null;

                if ($seriesScore !== null && $mapScore !== null) {
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
                        'start_time' => $command['is_range']
                            ? $match['start_at']->format('m/d H:i')
                            : $match['start_at']->format('H:i'),
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
     * @return array{
     *     games: array<int, string>,
     *     start_date: CarbonImmutable,
     *     end_date: CarbonImmutable,
     *     is_range: bool,
     *     date: CarbonImmutable,
     *     tiers: array<int, string>,
     *     limit: int,
     *     team: ?string
     * }|string|null
     */
    private function parseCommand(string $message): array|string|null
    {
        if (! preg_match('/^!(賽程|schedule|match|matches|lol|val|cs2|cs)(?:\s+(.*))?$/iu', $message, $matches)) {
            return null;
        }

        $commandKey = mb_strtolower($matches[1]);
        $rawArguments = trim($matches[2] ?? '');

        $dateAtom = '(?:今天|明天|後天|\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}|\d{1,2}[-\/\.]\d{1,2}|\d{4})';
        $rangePattern = '/^('.$dateAtom.'\s*(?:~|～)\s*'.$dateAtom.')(?:\s+(.*))?$/iu';
        $singlePattern = '/^('.$dateAtom.')(?:\s+(.*))?$/iu';

        if (preg_match($rangePattern, $rawArguments, $m)) {
            $datePart = $m[1];
            $optionsPart = $m[2] ?? '';
        } elseif (preg_match($singlePattern, $rawArguments, $m)) {
            $datePart = $m[1];
            $optionsPart = $m[2] ?? '';
        } else {
            $datePart = null;
            $optionsPart = $rawArguments;
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $dateInfo = $this->parseDateToken($datePart, $timezone);

        if (is_string($dateInfo)) {
            return $dateInfo;
        }

        if ($dateInfo === null) {
            return null;
        }

        $options = $this->parseOptions($optionsPart);

        if ($options === null) {
            return null;
        }

        if (in_array($commandKey, ['賽程', 'schedule', 'match', 'matches'], true)) {
            $games = $options['games'] ?? ['lol', 'valorant', 'cs'];
        } else {
            $defaultGame = ['lol' => 'lol', 'val' => 'valorant', 'cs' => 'cs', 'cs2' => 'cs'][$commandKey];
            $games = $options['games'] ?? [$defaultGame];
        }

        return [
            'games' => $games,
            'start_date' => $dateInfo['start_date'],
            'end_date' => $dateInfo['end_date'],
            'is_range' => $dateInfo['is_range'],
            'date' => $dateInfo['start_date'],
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

    /**
     * @return array{start_date: CarbonImmutable, end_date: CarbonImmutable, is_range: bool}|string|null
     */
    private function parseDateToken(?string $token, string $timezone): array|string|null
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($token === null || trim($token) === '') {
            return [
                'start_date' => $today,
                'end_date' => $today,
                'is_range' => false,
            ];
        }

        if (preg_match('/[~～]/u', $token)) {
            $parts = preg_split('/\s*[~～]\s*/u', trim($token), 2);
            if (! is_array($parts) || count($parts) !== 2) {
                return null;
            }

            $startDate = $this->parseSingleDate($parts[0], $today, $timezone);
            $endDate = $this->parseSingleDate($parts[1], $today, $timezone);

            if ($startDate === null || $endDate === null) {
                return null;
            }

            if ($endDate->lt($startDate)) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }

            $days = (int) $startDate->diffInDays($endDate) + 1;
            if ($days > self::MAX_SCHEDULE_DAYS) {
                return sprintf('查詢區間最多支援 %d 天，請縮小日期範圍再試。', self::MAX_SCHEDULE_DAYS);
            }

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_range' => ! $startDate->isSameDay($endDate),
            ];
        }

        $date = $this->parseSingleDate($token, $today, $timezone);
        if ($date === null) {
            return null;
        }

        return [
            'start_date' => $date,
            'end_date' => $date,
            'is_range' => false,
        ];
    }

    private function parseSingleDate(string $value, CarbonImmutable $today, string $timezone): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '今天') {
            return $today;
        }

        if ($value === '明天') {
            return $today->addDay();
        }

        if ($value === '後天') {
            return $today->addDays(2);
        }

        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $value, $m)) {
            $y = (int) $m[1];
            $mon = (int) $m[2];
            $d = (int) $m[3];
            if (! checkdate($mon, $d, $y)) {
                return null;
            }

            return CarbonImmutable::createFromDate($y, $mon, $d, $timezone)->startOfDay();
        }

        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})$/', $value, $m)) {
            $mon = (int) $m[1];
            $d = (int) $m[2];
            if (! checkdate($mon, $d, $today->year)) {
                return null;
            }

            return CarbonImmutable::createFromDate($today->year, $mon, $d, $timezone)->startOfDay();
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $value, $m)) {
            $mon = (int) $m[1];
            $d = (int) $m[2];
            if (! checkdate($mon, $d, $today->year)) {
                return null;
            }

            return CarbonImmutable::createFromDate($today->year, $mon, $d, $timezone)->startOfDay();
        }

        return null;
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
        return "指令格式：\n!match｜!lol｜!val｜!cs（未填日期預設今天）\n!賽程 08/15 game=lol/val/cs\n!lol 今天｜!val 明天｜!cs 08/11\n!lol 0912 或 !lol 0912~0913（區間最多 7 天）\n\n查今天顯示滾球中和尚未開打的賽事，預設查 S Tier。\n可選參數：game=lol/val/cs｜tier=s,a｜tier=all｜limit=5｜team=G2";
    }
}
