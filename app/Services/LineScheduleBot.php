<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Normalizer;
use Throwable;

class LineScheduleBot
{
    public const MAX_SCHEDULE_DAYS = 7;

    private const GAME_LABELS = [
        'cs' => 'CS2',
        'valorant' => 'VALORANT',
        'lol' => 'LoL',
        'mlb' => 'MLB',
        'nba' => 'NBA',
    ];

    public function __construct(
        private readonly Bo3ScheduleService $schedules,
        private readonly OddsApiService $odds,
        private readonly Bo3OddsService $bo3Odds,
        private readonly Bo3HeadToHeadService $headToHead,
        private readonly LolLiveScoreService $liveScores,
        private readonly MlbScheduleService $mlb,
        private readonly NbaScheduleService $nba,
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
                return new LineBotReply("指令格式：\n!bet\n查詢 Stake 進行中的體育投注、即時賽況與兌現。\n\n!r 或 !record [日期或區間]\n查詢指定日期或區間之投注紀錄與損益統計圖（支援 09-06、09-01~09-06、近7天、本週等）。\n\n!balance 或 !bal [天數]\n查詢資金水位長條圖（預設 3 天，最多 30 天，依每單結盤時間點繪製）。\n\n!bet balance\n查詢 Stake 帳號 USDT 即時資金水位。");
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

        if (preg_match('/^!(?:balance|bal|equity|water|chart|水位|資金水位|資金)(?:\s+(.*))?$/iu', $message, $matches)) {
            $argument = trim($matches[1] ?? '');
            if (mb_strtolower($argument) === 'help') {
                return new LineBotReply("指令格式：\n!balance 或 !bal 或 !水位 [天數]\n查詢 Stake 資金水位的長條圖（根據每一單結盤時間點繪製）。\n\n預設回傳近 3 天，最多可查詢 30 天。\n\n支援範例：\n・!balance（預設 3 天）\n・!bal 7d 或 !bal 7天\n・!水位 30d\n・加 text 查看純文字（例如 !balance 3d text）");
            }

            return ($this->stake ?? app(StakeBetService::class))->handleBalanceChartReply($argument);
        }

        $command = $this->parseCommand($message);

        if (is_string($command)) {
            return new LineBotReply($command);
        }

        if ($command === null) {
            return null;
        }

        $timings = [];
        $stageStartedAt = hrtime(true);
        $markTiming = static function (string $stage) use (&$timings, &$stageStartedAt): void {
            $now = hrtime(true);
            $timings[$stage] = (int) round(($now - $stageStartedAt) / 1_000_000);
            $stageStartedAt = $now;
        };
        $allMatches = [];
        $sourceErrors = [];
        $esports = array_values(array_diff($command['games'], ['mlb', 'nba']));
        $hasMlb = in_array('mlb', $command['games'], true);
        $hasNba = in_array('nba', $command['games'], true);
        $sources = [];
        if ($esports !== []) {
            $sources['bo3.gg'] = fn (): array => $this->schedules->forRange(
                $esports, $command['start_date'], $command['end_date'], $command['tiers'],
            );
        }
        if ($hasMlb) {
            $sources['MLB'] = fn (): array => ['matches' => $this->mlb->forRange($command['start_date'], $command['end_date'])];
        }
        if ($hasNba) {
            $sources['NBA'] = fn (): array => ['matches' => $this->nba->forRange($command['start_date'], $command['end_date'])];
        }
        foreach (ScheduleHttp::run($sources) as $source => $schedule) {
            if ($schedule instanceof Throwable) {
                report($schedule);
                $sourceErrors[] = $source;

                continue;
            }
            $allMatches = array_merge($allMatches, $schedule['matches']);
            if (($schedule['failed_requests'] ?? []) !== []) {
                $failed = array_map(
                    fn (array $request): string => (self::GAME_LABELS[$request['game']] ?? $request['game'])
                        .' '.CarbonImmutable::parse($request['date'])->format('m/d'),
                    $schedule['failed_requests'],
                );
                $sourceErrors[] = $source.'（'.implode('、', $failed).'）';
            }
        }
        $markTiming('schedule_sources_ms');
        foreach ($allMatches as $index => $match) {
            $allMatches[$index]['game_label'] = self::GAME_LABELS[$match['game']] ?? mb_strtoupper($match['game']);
        }

        $allMatches = array_values(collect($allMatches)->unique(function (array $match): string {
            return (string) ($match['url'] ?? ($match['game'] ?? '').$match['name'].$match['start_at']->toIso8601String());
        })->all());

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $now = CarbonImmutable::now($timezone);

        $includesToday = $command['start_date']->lessThanOrEqualTo($now)
            && $command['end_date']->greaterThanOrEqualTo($now->startOfDay());

        if ($command['team'] !== null) {
            $allMatches = array_values(array_filter(
                $allMatches,
                fn (array $match): bool => str_contains(
                    mb_strtolower($match['name']),
                    mb_strtolower($command['team']),
                ),
            ));
        }

        if ($includesToday) {
            // Upcoming matches cannot be excluded as finished yet. Defer their
            // missing formats until the display limit is known, even today.
            $todayMatches = array_filter($allMatches, fn (array $match): bool => $match['start_at']->isSameDay($now));
            $refreshMatches = array_filter($todayMatches, fn (array $match): bool => ($match['is_live'] ?? false)
                || $match['start_at']->lessThanOrEqualTo($now));
            $todayMatches = array_replace($todayMatches, $this->schedules->enrichLiveDetailsAndMissingFormats($refreshMatches));
            $allMatches = array_replace($allMatches, $this->liveScores->enrich($todayMatches));

            $allMatches = array_values(array_filter(
                $allMatches,
                fn (array $match): bool => ! $match['start_at']->isSameDay($now)
                    || (in_array($match['game'] ?? '', ['mlb', 'nba'], true)
                        ? ! ($match['is_finished'] || $match['is_cancelled'])
                        : (($match['is_live'] ?? false) || $match['start_at']->greaterThan($now))),
            ));
        }

        $markTiming('filter_and_live_scores_ms');
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
        $tierSuffix = $esports === [] ? '' : '｜'.$tierLabel;

        $urlDate = ($command['start_date']->lessThanOrEqualTo($now) && $command['end_date']->greaterThanOrEqualTo($now->startOfDay()))
            ? $now->startOfDay()
            : $command['start_date'];

        if ($isMultiGame) {
            $gameNames = implode('/', array_map(
                fn (string $g): string => self::GAME_LABELS[$g] ?? mb_strtoupper($g),
                $command['games'],
            ));
            $label = "綜合賽程（{$gameNames}）";
            $imageTitle = "綜合賽程｜{$dateLabel}{$tierSuffix}";
            $filteredUrl = $esports === []
                ? ($hasMlb ? $this->mlb->filteredUrl($urlDate) : $this->nba->filteredUrl($urlDate))
                : $this->multiGameFilteredUrl($urlDate, $command['tiers']);
        } else {
            $singleGame = $command['games'][0];
            $label = self::GAME_LABELS[$singleGame];
            $imageTitle = "{$label}｜{$dateLabel}{$tierSuffix}";
            $filteredUrl = match ($singleGame) {
                'mlb' => $this->mlb->filteredUrl($urlDate),
                'nba' => $this->nba->filteredUrl($urlDate),
                default => $this->schedules->filteredUrl($singleGame, $urlDate, $command['tiers']),
            };
        }

        $scheduleLinks = "完整賽程｜{$filteredUrl}";
        if ($hasMlb && $isMultiGame && $filteredUrl !== $this->mlb->filteredUrl($urlDate)) {
            $scheduleLinks .= "\nMLB 賽程｜".$this->mlb->filteredUrl($urlDate);
        }
        if ($hasNba && $isMultiGame && $filteredUrl !== $this->nba->filteredUrl($urlDate)) {
            $scheduleLinks .= "\nNBA 賽程｜".$this->nba->filteredUrl($urlDate);
        }
        $warning = $sourceErrors === [] ? '' : implode('、', $sourceErrors).' 暫時無法取得';

        $quickReplies = $this->scheduleQuickReplies($command, $now);

        if ($allMatches === []) {
            $this->logScheduleTimings($command, $timings, 0);
            $noMatchLabel = $isMultiGame ? '綜合賽程' : $label;
            $status = $sourceErrors === [] ? '查無賽程。' : '暫時無法取得完整賽程，請稍後再試。';

            return new LineBotReply(
                "{$noMatchLabel} {$dateLabel} {$status}\n{$scheduleLinks}".($warning === '' ? '' : "\n{$warning}"),
                $filteredUrl,
                null,
                $quickReplies,
            );
        }

        $visibleMatches = array_slice($allMatches, 0, $command['limit']);
        $markTiming('selection_ms');
        $enrichments = [];
        $esportsMatches = array_filter($visibleMatches, fn (array $match): bool => ! in_array($match['game'], ['mlb', 'nba'], true));
        if ($esportsMatches !== []) {
            $enrichments['esports'] = fn (): array => $this->headToHead->enrich(
                $this->schedules->enrichLiveDetailsAndMissingFormats($esportsMatches),
            );
        }
        $mlbMatches = array_filter($visibleMatches, fn (array $match): bool => $match['game'] === 'mlb');
        if ($mlbMatches !== []) {
            $enrichments['mlb'] = fn (): array => $this->mlb->enrichRecentForm($mlbMatches);
        }
        $nbaMatches = array_filter($visibleMatches, fn (array $match): bool => $match['game'] === 'nba');
        if ($nbaMatches !== []) {
            $enrichments['nba'] = fn (): array => $this->nba->enrichRecentForm($nbaMatches);
        }
        $enrichments['odds'] = fn (): array => $this->odds->enrich(
            $visibleMatches, $command['start_date'], $command['end_date'],
        );
        $enriched = ScheduleHttp::run($enrichments);
        foreach ($enriched as $kind => $matches) {
            if ($matches instanceof Throwable) {
                report($matches);

                continue;
            }
            if ($kind !== 'odds') {
                $visibleMatches = array_replace($visibleMatches, $matches);
            }
        }
        // Odds use the original selection, so merge only their own field to
        // preserve details and history supplied by the independent tasks.
        foreach ($visibleMatches as $index => $match) {
            $visibleMatches[$index]['odds'] = is_array($enriched['odds'])
                ? ($enriched['odds'][$index]['odds'] ?? null) : null;
        }
        $markTiming('parallel_enrichment_ms');
        $visibleMatches = $this->bo3Odds->enrichMissing($visibleMatches);
        $markTiming('fallback_odds_ms');
        $this->logScheduleTimings($command, $timings, count($visibleMatches));
        $lines = [
            "{$label}｜{$dateLabel}{$tierSuffix}",
            '時間基準｜台灣時間',
        ];
        if ($warning !== '') {
            $lines[] = $warning;
        }

        foreach ($visibleMatches as $index => $match) {
            $lines[] = "\n──────────";
            $gameTag = $isMultiGame ? sprintf('【%s】', $match['game_label'] ?? '') : '';
            $liveTag = ($match['is_live'] ?? false) ? '【滾球】' : '';
            $timeString = $command['is_range']
                ? $match['start_at']->format('m/d H:i')
                : $match['start_at']->format('H:i');
            if ($match['time_tbd'] ?? false) {
                $timeString = ($command['is_range'] ? $match['start_at']->format('m/d').' ' : '').'時間待定';
            }

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

            if (in_array($match['game'] ?? null, ['mlb', 'nba'], true)) {
                $lines[] = '客隊 vs 主隊｜'.($match['status_label'] ?? '');
            }
            $lines[] = '各隊近 5 場（新→舊）｜比分為本隊：對手';
            foreach (['team1', 'team2'] as $side) {
                $form = $match['recent_form'][$side] ?? null;
                $lines[] = $match[$side].'｜'.RecentForm::label($form);
                foreach (array_slice($form['matches'] ?? [], 0, 5) as $recent) {
                    $resultLabel = ['W' => '勝', 'L' => '敗', 'D' => '和'][$recent['result']] ?? '—';
                    $lines[] = sprintf('・%s %s｜%s %d：%d vs %s', $recent['date'], $recent['format'], $resultLabel, $recent['team_score'], $recent['opponent_score'], $recent['opponent']);
                }
            }

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
            } elseif (($match['is_finished'] ?? false) && ($match['score'] ?? null) !== null) {
                $lines[] = '終場比分｜'.$match['score'];
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
            $lines[] = sprintf("\n另有 %d 場，請至完整賽程查看。", count($allMatches) - $command['limit']);
        }

        $lines[] = "\n{$scheduleLinks}";

        $imageData = ($command['text_only'] ?? false) ? null : [
            'title' => $imageTitle,
            'subtitle' => '台灣時間｜'.count($visibleMatches).' 場賽程'.($warning === '' ? '' : '｜'.$warning),
            'game' => $isMultiGame ? 'all' : $command['games'][0],
            'matches' => array_map(
                fn (array $match): array => [
                    'game' => $match['game'] ?? ($command['games'][0] ?? null),
                    'start_time' => ($match['time_tbd'] ?? false) ? '時間待定' : ($command['is_range']
                        ? $match['start_at']->format('m/d H:i')
                        : $match['start_at']->format('H:i')),
                    'format' => $match['format'],
                    'is_live' => $match['is_live'] ?? false,
                    'series_score' => $match['series_score'] ?? null,
                    'score' => $match['score'] ?? null,
                    'team1' => $match['team1'],
                    'team2' => $match['team2'],
                    'tournament' => $match['tournament'],
                    'odds' => $match['odds'],
                    'h2h' => $match['h2h'] ?? null,
                    'recent_form' => $match['recent_form'] ?? null,
                    'status_label' => $match['status_label'] ?? null,
                ],
                $visibleMatches,
            ),
        ];

        return new LineBotReply(
            implode("\n", $lines),
            $filteredUrl,
            $imageData,
            $quickReplies,
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
     *     team: ?string,
     *     text_only: bool
     * }|string|null
     */
    private function parseCommand(string $message): array|string|null
    {
        if (! preg_match('/^!(賽程|schedule|match|matches|lol|val|cs2|cs|mlb|nba)(?:\s+(.*))?$/iu', $message, $matches)) {
            return null;
        }

        $commandKey = mb_strtolower($matches[1]);
        $rawArguments = trim($matches[2] ?? '');

        $dateAtom = '(?:今天|明天|後天|\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}|\d{1,2}[-\/\.]\d{1,2}|\d{4})';
        $rangePattern = '/^('.$dateAtom.'\s*(?:~|～)\s*'.$dateAtom.')(?:\s+(.*))?$/iu';
        $singlePattern = '/^('.$dateAtom.')(?:\s+(.*))?$/iu';
        $daysPattern = '/^(\d+d)(?:\s+(.*))?$/iu';

        if (preg_match($rangePattern, $rawArguments, $m)) {
            $datePart = $m[1];
            $optionsPart = $m[2] ?? '';
        } elseif (preg_match($singlePattern, $rawArguments, $m) || preg_match($daysPattern, $rawArguments, $m)) {
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
            $games = $options['games'] ?? ['lol', 'valorant', 'cs', 'mlb', 'nba'];
        } else {
            $defaultGame = ['lol' => 'lol', 'val' => 'valorant', 'cs' => 'cs', 'cs2' => 'cs', 'mlb' => 'mlb', 'nba' => 'nba'][$commandKey];
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
            'text_only' => $options['text_only'],
        ];
    }

    private function logScheduleTimings(array $command, array $timings, int $visibleCount): void
    {
        try {
            Log::channel('webhook')->log('info', 'LINE schedule query completed.', [
                'games' => $command['games'],
                'start_date' => $command['start_date']->format('Y-m-d'),
                'end_date' => $command['end_date']->format('Y-m-d'),
                'visible_matches' => $visibleCount,
                'duration_ms' => array_sum($timings),
                'stages' => $timings,
            ]);
        } catch (Throwable) {
            // Diagnostic logging must never prevent a schedule reply.
        }
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

        if (preg_match('/^(\d+)d$/i', $token, $matches)) {
            $days = (int) $matches[1];
            if ($days < 1 || $days > self::MAX_SCHEDULE_DAYS) {
                return sprintf('查詢天數請輸入 1～%d 天，例如 !match 2d（包含今天）。', self::MAX_SCHEDULE_DAYS);
            }

            return [
                'start_date' => $today,
                'end_date' => $today->addDays($days - 1),
                'is_range' => $days > 1,
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
     * @return array{games?: array<int, string>, tiers: array<int, string>, limit: int, team: ?string, text_only: bool}|null
     */
    private function parseOptions(string $input): ?array
    {
        // A typical combined S-tier day can exceed ten matches. Keep the
        // default large enough for the full image while retaining an explicit
        // limit option for callers that want a shorter response.
        $options = ['tiers' => ['s'], 'limit' => 19, 'team' => null, 'text_only' => false];

        if (trim($input) === '') {
            return $options;
        }

        if (preg_match('/(?:^|\s)text(?=\s|$)/iu', $input) === 1) {
            $options['text_only'] = true;
            $input = trim((string) preg_replace('/(?:^|\s)text(?=\s|$)/iu', ' ', $input));
            if ($input === '') {
                return $options;
            }
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
                    'mlb' => 'mlb',
                    'nba' => 'nba',
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

    /**
     * @param  array{
     *     games: array<int, string>,
     *     start_date: CarbonImmutable,
     *     end_date: CarbonImmutable,
     *     is_range: bool,
     *     tiers: array<int, string>
     * }  $command
     * @return array<int, array{label: string, text: string}>
     */
    public function scheduleQuickReplies(array $command, CarbonImmutable $now): array
    {
        $isMultiGame = count($command['games']) > 1;
        $singleGame = $isMultiGame ? null : ($command['games'][0] ?? null);
        $baseCmd = match ($singleGame) {
            'lol' => '!lol',
            'valorant' => '!val',
            'cs' => '!cs',
            'mlb' => '!mlb',
            'nba' => '!nba',
            default => '!match',
        };

        $isToday = ! $command['is_range'] && $command['start_date']->isSameDay($now);

        $items = [];
        if ($isToday) {
            $items[] = ['label' => '🔄 刷新賽程', 'text' => $baseCmd];
            $items[] = ['label' => '⏭️ 明天賽程', 'text' => $baseCmd.' 明天'];
        } else {
            $items[] = ['label' => '📅 今天賽程', 'text' => $baseCmd];
            $items[] = ['label' => '⏭️ 明天賽程', 'text' => $baseCmd.' 明天'];
        }

        $gameButtons = [
            'all' => ['label' => '🎮 綜合賽程', 'text' => '!match'],
            'lol' => ['label' => '⚔️ LoL 賽程', 'text' => '!lol'],
            'valorant' => ['label' => '🔫 VAL 賽程', 'text' => '!val'],
            'cs' => ['label' => '💣 CS2 賽程', 'text' => '!cs'],
            'mlb' => ['label' => '⚾ MLB 賽程', 'text' => '!mlb'],
            'nba' => ['label' => '🏀 NBA 賽程', 'text' => '!nba'],
        ];

        $currentKey = $isMultiGame ? 'all' : (string) $singleGame;
        foreach ($gameButtons as $key => $btn) {
            if ($key !== $currentKey) {
                $items[] = $btn;
            }
        }

        if (array_diff($command['games'], ['mlb', 'nba']) !== [] && $command['tiers'] === ['s']) {
            $items[] = ['label' => '🏆 S+A 級賽事', 'text' => $baseCmd.' tier=s,a'];
        }

        $items[] = ['label' => '🎯 進行中注單', 'text' => '!bet'];

        return $items;
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
        return "指令格式：\n!match｜!lol｜!val｜!cs｜!mlb｜!nba（未填日期預設今天）\n!賽程 08/15 game=lol/val/cs/mlb/nba\n!lol 今天｜!val 明天｜!cs 08/11\n!lol 0912 或 !lol 0912~0913（區間最多 7 天）\n!match 2d（含今天起 2 天，支援 1d～7d）\n\n查今天顯示滾球中和尚未開打的賽事，電競預設查 S Tier。\nMLB 依 config/mlb.php 球隊清單查詢（預設道奇、釀酒人），例如 !mlb 明天 team=道奇。\nNBA 依 config/nba.php 球隊清單查詢（預設76人、溜馬、尼克、熱火、雷霆、馬刺、灰狼、勇士），例如 !nba 明天 team=勇士。\n各隊近 5 場顯示已完賽勝敗（新→舊），電競以系列賽計算。\n可選參數：game=lol/val/cs/mlb/nba｜tier=s,a｜tier=all｜limit=5｜team=G2";
    }
}
