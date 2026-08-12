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

    public function __construct(
        private readonly Bo3ScheduleService $schedules,
        private readonly OddsApiService $odds,
        private readonly Bo3OddsService $bo3Odds,
    ) {}

    public function respond(string $message): ?string
    {
        return $this->reply($message)?->text;
    }

    public function isScheduleQuery(string $message): bool
    {
        return $this->parseCommand($message) !== null;
    }

    public function reply(string $message): ?LineBotReply
    {
        if (mb_strtolower(trim($message)) === '!help') {
            return new LineBotReply($this->help());
        }

        $command = $this->parseCommand($message);

        if ($command === null) {
            return null;
        }

        try {
            $matches = $this->schedules->forDate(
                $command['game'],
                $command['date'],
                $command['tiers'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return new LineBotReply('目前無法取得 bo3.gg 賽程，請稍後再試。');
        }

        if ($command['team'] !== null) {
            $matches = array_values(array_filter(
                $matches,
                fn (array $match): bool => str_contains(
                    mb_strtolower($match['name']),
                    mb_strtolower($command['team']),
                ),
            ));
        }

        $label = self::GAME_LABELS[$command['game']];
        $dateLabel = $command['date']->format('m/d');
        $tierLabel = $command['tiers'] === []
            ? '全部 Tier'
            : implode('/', array_map('mb_strtoupper', $command['tiers'])).' Tier';
        $filteredUrl = $this->schedules->filteredUrl(
            $command['game'],
            $command['date'],
            $command['tiers'],
        );

        if ($matches === []) {
            return new LineBotReply(
                "{$label} {$dateLabel} 查無賽程。\n完整賽程｜{$filteredUrl}",
                $filteredUrl,
            );
        }

        $visibleMatches = array_slice($matches, 0, $command['limit']);
        $visibleMatches = $this->odds->enrich($visibleMatches, $command['date']);
        $visibleMatches = $this->bo3Odds->enrichMissing($visibleMatches);
        $lines = [
            "{$label}｜{$dateLabel}｜{$tierLabel}",
            '時間基準｜台灣時間',
        ];

        foreach ($visibleMatches as $index => $match) {
            $lines[] = "\n──────────";
            $lines[] = sprintf(
                "第 %d 場｜%s｜%s\n%s\nvs\n%s\n\n賽事｜%s",
                $index + 1,
                $match['start_at']->format('H:i'),
                $match['format'],
                $match['team1'],
                $match['team2'],
                $match['tournament'],
            );

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
        }

        if (count($matches) > $command['limit']) {
            $lines[] = sprintf("\n另有 %d 場，請至 bo3.gg 查看。", count($matches) - $command['limit']);
        }

        $lines[] = "\n完整賽程｜{$filteredUrl}";

        return new LineBotReply(
            implode("\n", $lines),
            $filteredUrl,
            [
                'title' => "{$label}｜{$dateLabel}｜{$tierLabel}",
                'subtitle' => '台灣時間｜'.count($visibleMatches).' 場賽程',
                'matches' => array_map(
                    fn (array $match): array => [
                        'start_time' => $match['start_at']->format('H:i'),
                        'format' => $match['format'],
                        'team1' => $match['team1'],
                        'team2' => $match['team2'],
                        'tournament' => $match['tournament'],
                        'odds' => $match['odds'],
                    ],
                    $visibleMatches,
                ),
            ],
        );
    }

    /**
     * @return array{game: string, date: CarbonImmutable, tiers: array<int, string>, limit: int, team: ?string}|null
     */
    private function parseCommand(string $message): ?array
    {
        if (! preg_match('/^!(lol|val|cs)\s+(今天|明天|後天|\d{1,2}\/\d{1,2})(?:\s+(.*))?$/iu', trim($message), $matches)) {
            return null;
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $date = $this->parseDate($matches[2], $timezone);

        if ($date === null) {
            return null;
        }

        $options = $this->parseOptions($matches[3] ?? '');

        if ($options === null) {
            return null;
        }

        return [
            'game' => ['lol' => 'lol', 'val' => 'valorant', 'cs' => 'cs'][mb_strtolower($matches[1])],
            'date' => $date,
            ...$options,
        ];
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
     * @return array{tiers: array<int, string>, limit: int, team: ?string}|null
     */
    private function parseOptions(string $input): ?array
    {
        $options = ['tiers' => ['s', 'a'], 'limit' => 10, 'team' => null];

        if (trim($input) === '') {
            return $options;
        }

        preg_match_all('/(tier|limit|team)=(?:"([^"]+)"|(\S+))/iu', $input, $optionMatches, PREG_SET_ORDER);
        $consumed = trim((string) preg_replace('/(tier|limit|team)=(?:"[^"]+"|\S+)/iu', '', $input));

        if ($consumed !== '') {
            return null;
        }

        foreach ($optionMatches as $option) {
            $key = mb_strtolower($option[1]);
            $value = trim($option[2] !== '' ? $option[2] : $option[3]);

            if ($key === 'tier') {
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
                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 10) {
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

    private function help(): string
    {
        return "指令格式：\n!lol 今天\n!val 明天\n!cs 08/11\n\n預設查 S/A Tier。\n可選參數：tier=s,a｜tier=all｜limit=5｜team=G2";
    }
}
