<?php

namespace App\Services;

use App\Support\ChineseConverter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class StakeBetService
{
    public const ACTIVE_BET_COUNT_QUERY = <<<'GRAPHQL'
query ActiveBetCount_User($name: String) {
  user(name: $name) {
    activeSportBetCount
    activeSwishBetCount
    activeRacingBetCount
    activeSportsbookXMultiBetCount
  }
}
GRAPHQL;

    public const USER_BALANCES_QUERY = <<<'GRAPHQL'
query UserBalances {
  user {
    id
    balances {
      available {
        amount
        currency
        __typename
      }
      vault {
        amount
        currency
        __typename
      }
      __typename
    }
    __typename
  }
}
GRAPHQL;

    public const SPORT_BET_FRAGMENTS = <<<'GRAPHQL'
fragment SportBetPreview_SportBet on SportBet {
  __typename
  id
  active
  status
  customBet
  cashoutDisabled
  customPrices {
    __typename
    customOdds
    type
    stakeShield {
      __typename
      offerOdds
      protectionLevel
    }
    promotion {
      __typename
      id
      name
      rule {
        __typename
        boostLadder {
          __typename
          legs
          boost
        }
      }
    }
  }
  amount
  activeAmount
  currency
  payout
  potentialMultiplier
  payoutMultiplier
  cashoutMultiplier
  cashouts {
    __typename
    cashoutAmount
    createdAt
  }
  createdAt
  updatedAt
  bet {
    __typename
    iid
  }
  user {
    __typename
    id
  }
  promotionBet {
    __typename
    status
    payout
    currency
    promotion {
      __typename
      name
    }
  }
  adjustments {
    __typename
    payoutMultiplier
  }
  outcomes {
    __typename
    fixture {
      __typename
      tournament {
        __typename
        category {
          __typename
          sport {
            __typename
            cashoutConfiguration {
              __typename
              cashoutEnabled
              baseLoad
              varianceSensitivity
            }
          }
        }
      }
    }
  }
  ...SportBetPreviewOutcomeGroup_SportBet
}

fragment SportBetPreviewOutcomeGroup_SportBet on SportBet {
  __typename
  outcomes {
    __typename
    id
    odds
    totalOdds
    status
    outcome {
      __typename
      id
      name
      odds
      active
      customBetAvailable
      probabilities
      voidFactor
      payout
    }
    market {
      __typename
      id
      name
      status
      extId
      specifiers
      customBetAvailable
      provider
    }
    fixture {
      __typename
      ...MatchInformation_SportFixture
      marketCount(status: [active, suspended])
      cashoutEnabled
      eventStatus {
        __typename
        ...SportFixtureEventStatus
        ...EsportFixtureEventStatus
      }
      tournament {
        __typename
        id
        name
        cashoutEnabled
        category {
          __typename
          id
          name
          cashoutEnabled
          sport {
            __typename
            id
            name
            slug
          }
        }
      }
      data {
        __typename
        ... on SportFixtureDataMatch {
          __typename
          teams {
            __typename
            name
            qualifier
          }
        }
        ... on SportFixtureDataOutright {
          __typename
          endTime
        }
      }
    }
  }
}

fragment MatchInformation_SportFixture on SportFixture {
  __typename
  ...FixtureStatus_SportFixture
  ...FixtureOptions_SportFixture
  ...CompactScoreboard_SportFixture
}

fragment FixtureStatus_SportFixture on SportFixture {
  __typename
  id
  status
  ...FixtureOptions_SportFixture
  ...Live_SportFixture
  ...Active_SportFixture
}

fragment FixtureOptions_SportFixture on SportFixture {
  __typename
  ...FixtureOptionsOddin_SportFixture
  ...FixtureOptionsBetRadar_SportFixture
}

fragment FixtureOptionsOddin_SportFixture on SportFixture {
  __typename
  id
  extId
  stakeFixtureId
  name
  provider
  status
  widgetUrl
  liveWidgetUrl
  streamExists
  tournament {
    __typename
    id
    slug
    frontRowSeatEvent {
      __typename
      identifier
    }
    category {
      __typename
      slug
      sport {
        __typename
        slug
      }
    }
  }
  data {
    __typename
    ... on SportFixtureDataMatch {
      __typename
      competitors {
        __typename
        name
        abbreviation
      }
      startTime
    }
    ... on SportFixtureDataOutright {
      __typename
      name
      startTime
    }
  }
}

fragment FixtureOptionsBetRadar_SportFixture on SportFixture {
  __typename
  id
  extId
  stakeFixtureId
  name
  provider
  status
  widgetUrl
  liveWidgetUrl
  streamExists
  frontRowSeatFight {
    __typename
    fightId
  }
  tournament {
    __typename
    id
    slug
    frontRowSeatEvent {
      __typename
      identifier
    }
    category {
      __typename
      slug
      sport {
        __typename
        slug
      }
    }
  }
  data {
    __typename
    ... on SportFixtureDataMatch {
      __typename
      competitors {
        __typename
        name
        abbreviation
      }
      startTime
    }
    ... on SportFixtureDataOutright {
      __typename
      name
      startTime
    }
  }
}

fragment Live_SportFixture on SportFixture {
  __typename
  provider
  eventStatus {
    __typename
    ... on SportFixtureEventStatusData {
      __typename
      matchStatus
      periodScores {
        __typename
        matchStatus
      }
      clock {
        __typename
        matchTime
        remainingTime
        stopped
      }
    }
    ... on EsportFixtureEventStatus {
      __typename
      matchStatus
      periodScores {
        __typename
        matchStatus
      }
      scoreboard {
        __typename
        gameTime
        remainingGameTime
      }
    }
  }
  tournament {
    __typename
    category {
      __typename
      sport {
        __typename
        slug
      }
    }
  }
}

fragment Active_SportFixture on SportFixture {
  __typename
  data {
    __typename
    ... on SportFixtureDataOutright {
      __typename
      endTime
    }
    ... on SportFixtureDataMatch {
      __typename
      startTime
    }
  }
}

fragment CompactScoreboard_SportFixture on SportFixture {
  __typename
  ...FixtureScoreboard_SportFixture
  ...CricketScoreboard_SportFixture
}

fragment FixtureScoreboard_SportFixture on SportFixture {
  __typename
  ...FixtureCompetitors_SportFixture
  status
  eventStatus {
    __typename
    ... on SportFixtureEventStatusData {
      __typename
      homeScore
      awayScore
      matchStatus
      homeGameScore
      awayGameScore
      periodScores {
        __typename
        homeScore
        awayScore
      }
    }
    ... on EsportFixtureEventStatus {
      __typename
      homeScore
      awayScore
      periodScores {
        __typename
        homeScore
        awayScore
      }
      scoreboard {
        __typename
        homeKills
        homeWonRounds
        awayKills
        awayWonRounds
      }
    }
  }
}

fragment FixtureCompetitors_SportFixture on SportFixture {
  __typename
  id
  name
  slug
  provider
  tournament {
    __typename
    slug
    category {
      __typename
      slug
      sport {
        __typename
        slug
      }
    }
  }
  eventStatus {
    __typename
    ... on SportFixtureEventStatusData {
      __typename
      currentTeamServing
    }
  }
  data {
    __typename
    ... on SportFixtureDataMatch {
      __typename
      isOutright
      teams {
        __typename
        extId
        name
        qualifier
      }
      competitors {
        __typename
        ...CompetitorIcon_SportFixtureCompetitor
      }
    }
    ... on SportFixtureDataOutright {
      __typename
      isOutright
    }
  }
}

fragment CompetitorIcon_SportFixtureCompetitor on SportFixtureCompetitor {
  __typename
  name
  defaultName
  extId
  iconPath
  countryCode
  country
}

fragment CricketScoreboard_SportFixture on SportFixture {
  __typename
  ...Competitors_SportFixture
  status
  tournament {
    __typename
    category {
      __typename
      sport {
        __typename
        slug
      }
    }
  }
  eventStatus {
    __typename
    ... on SportFixtureEventStatusData {
      __typename
      matchStatus
    }
  }
}

fragment Competitors_SportFixture on SportFixture {
  __typename
  ...FixtureCompetitors_SportFixture
  extId
  eventStatus {
    __typename
    ... on SportFixtureEventStatusData {
      __typename
      matchStatus
    }
  }
}

fragment SportFixtureEventStatus on SportFixtureEventStatusData {
  __typename
  homeScore
  awayScore
  matchStatus
  clock {
    __typename
    matchTime
    remainingTime
  }
  periodScores {
    __typename
    homeScore
    awayScore
    matchStatus
  }
  currentTeamServing
  homeGameScore
  awayGameScore
  statistic {
    __typename
    yellowCards {
      __typename
      away
      home
    }
    redCards {
      __typename
      away
      home
    }
    corners {
      __typename
      home
      away
    }
  }
}

fragment EsportFixtureEventStatus on EsportFixtureEventStatus {
  matchStatus
  homeScore
  awayScore
  scoreboard {
    __typename
    homeGold
    awayGold
    homeGoals
    awayGoals
    homeKills
    awayKills
    gameTime
    homeDestroyedTowers
    awayDestroyedTowers
    homeDestroyedTurrets
    awayDestroyedTurrets
    currentRound
    currentCtTeam
    currentDefTeam
    time
    awayWonRounds
    homeWonRounds
    remainingGameTime
  }
  periodScores {
    __typename
    type
    number
    awayGoals
    awayKills
    awayScore
    homeGoals
    homeKills
    homeScore
    awayWonRounds
    homeWonRounds
    matchStatus
  }
  __typename
}
GRAPHQL;

    public const FETCH_ACTIVE_SPORT_BETS_QUERY = <<<'GRAPHQL'
query FetchActiveSportBets($limit: Int!, $offset: Int!, $name: String) {
  user(name: $name) {
    id
    activeSportBets(limit: $limit, offset: $offset, sort: placedTime) {
      ...SportBetPreview_SportBet
    }
  }
}
GRAPHQL."\n".self::SPORT_BET_FRAGMENTS;

    public const FETCH_SPORT_BET_LIST_QUERY = <<<'GRAPHQL'
query FetchSportBetList($limit: Int, $offset: Int, $status: [SportBetStatusEnum!]) {
  user {
    id
    sportBetList(limit: $limit, offset: $offset, status: $status) {
      id
      iid
      bet {
        __typename
        ...SportBetPreview_SportBet
      }
    }
  }
}
GRAPHQL."\n".self::SPORT_BET_FRAGMENTS;

    public const MAX_HISTORY_DAYS = 30;

    public const MAX_DISPLAY_RECORD_BETS = 10;

    public function reply(string $argument = ''): LineBotReply
    {
        $token = trim((string) config('services.stake.access_token'));

        if ($token === '') {
            return new LineBotReply('尚未設定 Stake Access Token，請在環境變數中設定 STAKE_ACCESS_TOKEN。');
        }

        $trimmedArg = trim($argument);
        $lowerArg = mb_strtolower($trimmedArg);

        if (in_array($lowerArg, ['balance', 'bal', '水位', '資金', '餘額', 'usdt'], true)) {
            try {
                $balance = $this->getUsdtBalance();
                if ($balance === null) {
                    return new LineBotReply("Stake 體育投注｜即時資金水位\n目前無法取得 Stake 帳號資金水位，請稍後再試。\n完整注單｜https://stake.com/zh/my-bets/sports");
                }

                return new LineBotReply(sprintf(
                    "Stake 體育投注｜即時資金水位\n資金水位｜%s\n完整注單｜https://stake.com/zh/my-bets/sports",
                    $this->formatBalanceLine($balance)
                ), 'https://stake.com/zh/my-bets/sports');
            } catch (RequestException $exception) {
                return $this->handleRequestException($exception);
            } catch (ConnectionException $exception) {
                return $this->handleConnectionException($exception);
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Stake API processing failed.', [
                    'type' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                return new LineBotReply('目前無法取得 Stake 帳號資金水位，請稍後再試。');
            }
        }

        if (preg_match('/^(?:balance|bal|水位|資金水位|資金|chart)\s+(.+)$/iu', $trimmedArg, $balMatches)) {
            $balSubArg = trim($balMatches[1] ?? '');
            if ($balSubArg !== '' && mb_strtolower($balSubArg) !== 'now' && mb_strtolower($balSubArg) !== '即時') {
                return $this->handleBalanceChartReply($balSubArg);
            }
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $historyParams = $this->parseHistoryArgument($trimmedArg, $timezone);
        if ($historyParams !== null) {
            return $this->handleBetHistoryReply(
                $historyParams['start_date'],
                $historyParams['end_date'],
                $historyParams['force_text'],
                $timezone
            );
        }

        try {
            $count = $this->getActiveBetCount();

            if ($count === 0) {
                $balance = null;
                try {
                    $balance = $this->getUsdtBalance();
                } catch (Throwable $e) {
                    Log::warning('Stake USDT balance fetch failed during bet reply.', [
                        'error' => $e->getMessage(),
                    ]);
                }

                $lines = [
                    '目前無進行中的 Stake 體育注單。',
                ];
                if ($balance !== null) {
                    $lines[] = '資金水位｜'.$this->formatBalanceLine($balance);
                }
                $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

                return new LineBotReply(implode("\n", $lines), 'https://stake.com/zh/my-bets/sports');
            }

            $limit = 20;
            $forceText = false;
            if (in_array($lowerArg, ['text', 'txt', '文字'], true)) {
                $forceText = true;
            } elseif (ctype_digit($trimmedArg) && (int) $trimmedArg > 0) {
                $limit = min((int) $trimmedArg, 20);
            }

            $bets = $this->getActiveSportBets($limit);

            $balance = null;
            try {
                $balance = $this->getUsdtBalance();
            } catch (Throwable $e) {
                Log::warning('Stake USDT balance fetch failed during bet reply.', [
                    'error' => $e->getMessage(),
                ]);
            }

            if ($bets === []) {
                $lines = [
                    '目前無進行中的 Stake 體育注單。',
                ];
                if ($balance !== null) {
                    $lines[] = '資金水位｜'.$this->formatBalanceLine($balance);
                }
                $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

                return new LineBotReply(implode("\n", $lines), 'https://stake.com/zh/my-bets/sports');
            }

            $text = $this->formatBetsMessage($bets, $count, $balance);
            $linkUrl = 'https://stake.com/zh/my-bets/sports';

            $imageData = null;
            if (! $forceText) {
                $imageData = $this->buildImageData($bets, $count, $balance);
            }

            return new LineBotReply($text, $linkUrl, $imageData);
        } catch (RequestException $exception) {
            return $this->handleRequestException($exception);
        } catch (ConnectionException $exception) {
            return $this->handleConnectionException($exception);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Stake API processing failed.', [
                'type' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return new LineBotReply('目前無法取得 Stake 投注資訊，請稍後再試。');
        }
    }

    private function handleRequestException(RequestException $exception): LineBotReply
    {
        $status = $exception->response->status();
        Log::warning('Stake API request failed.', [
            'status' => $status,
            'body' => mb_substr($exception->response->body(), 0, 500),
        ]);

        return new LineBotReply(match ($status) {
            401 => 'Stake 認證失敗，Access Token 無效或已過期，請更新 Token 後再試。',
            403 => 'Stake API 存取受限（觸發安全驗證防護或權限不足），請稍後再試。',
            451 => 'Stake API 存取受限（區域限制 HTTP 451，需透過代理連線）。',
            default => '目前無法取得 Stake 投注資訊，請稍後再試。',
        });
    }

    private function handleConnectionException(ConnectionException $exception): LineBotReply
    {
        $hasProxy = filled(config('services.stake.proxy'));
        Log::warning('Stake API connection failed.', [
            'error' => $exception->getMessage(),
            'has_proxy' => $hasProxy,
        ]);

        return new LineBotReply(
            $hasProxy
                ? '連線至 Stake 代理伺服器失敗或超時，請檢查 STAKE_PROXY 設定後再試。'
                : '連線至 Stake 伺服器超時，請稍後再試。'
        );
    }

    public function getActiveBetCount(): int
    {
        $cacheSeconds = (int) config('services.stake.cache_seconds', 0);
        $cacheKey = 'stake:active_bet_count';

        if ($cacheSeconds > 0) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_int($cached)) {
                    return $cached;
                }
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        $response = $this->sendGraphQLRequest('ActiveBetCount_User', self::ACTIVE_BET_COUNT_QUERY, []);

        $count = (int) ($response['data']['user']['activeSportBetCount'] ?? 0);

        if ($cacheSeconds > 0) {
            try {
                Cache::put($cacheKey, $count, $cacheSeconds);
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveSportBets(int $limit = 10, int $offset = 0): array
    {
        $cacheSeconds = (int) config('services.stake.cache_seconds', 0);
        $cacheKey = "stake:active_sport_bets:{$limit}:{$offset}";

        if ($cacheSeconds > 0) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        $response = $this->sendGraphQLRequest(
            'FetchActiveSportBets',
            self::FETCH_ACTIVE_SPORT_BETS_QUERY,
            ['limit' => $limit, 'offset' => $offset]
        );

        $bets = $response['data']['user']['activeSportBets'] ?? [];

        if (! is_array($bets)) {
            return [];
        }

        if ($cacheSeconds > 0) {
            try {
                Cache::put($cacheKey, $bets, $cacheSeconds);
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        return array_values(array_filter($bets, 'is_array'));
    }

    public function parseDateString(string $str, CarbonImmutable $today, string $timezone): ?CarbonImmutable
    {
        $trimmed = trim($str);
        if ($trimmed === '') {
            return null;
        }

        $ld = mb_strtolower($trimmed);
        if (in_array($ld, ['today', '今天', '今日'], true)) {
            return $today;
        }
        if (in_array($ld, ['yesterday', '昨天', '昨日'], true)) {
            return $today->subDay();
        }
        if (in_array($ld, ['before_yesterday', '前天', '前日'], true)) {
            return $today->subDays(2);
        }

        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $trimmed, $m)) {
            $y = (int) $m[1];
            $mon = (int) $m[2];
            $d = (int) $m[3];
            if (! checkdate($mon, $d, $y)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($y, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $trimmed, $m)) {
            $y = (int) $m[1];
            $mon = (int) $m[2];
            $d = (int) $m[3];
            if (! checkdate($mon, $d, $y)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($y, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})[日號]?$/u', $trimmed, $m)) {
            $y = (int) $m[1];
            $mon = (int) $m[2];
            $d = (int) $m[3];
            if (! checkdate($mon, $d, $y)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($y, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})$/', $trimmed, $m)) {
            $mon = (int) $m[1];
            $d = (int) $m[2];
            if (! checkdate($mon, $d, $today->year)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($today->year, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^(\d{2})(\d{2})$/', $trimmed, $m)) {
            $mon = (int) $m[1];
            $d = (int) $m[2];
            if (! checkdate($mon, $d, $today->year)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($today->year, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^(\d{1,2})月(\d{1,2})[日號]?$/u', $trimmed, $m)) {
            $mon = (int) $m[1];
            $d = (int) $m[2];
            if (! checkdate($mon, $d, $today->year)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromDate($today->year, $mon, $d, $timezone)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{start_date: CarbonImmutable, end_date: CarbonImmutable, is_range: bool, date: CarbonImmutable, force_text: bool}|null
     */
    public function parseHistoryArgument(string $argument, string $timezone): ?array
    {
        $trimmed = trim($argument);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed);
        if (in_array($lower, ['balance', 'bal', '水位', '資金', '餘額', 'usdt', 'text', 'txt', '文字'], true)) {
            return null;
        }

        if (ctype_digit($trimmed) && strlen($trimmed) <= 2 && (int) $trimmed <= 20) {
            return null;
        }

        $historyKeywords = ['history', 'record', 'records', '紀錄', '記錄', '歷史', '損益', 'pnl'];
        $parts = preg_split('/\s+/u', $trimmed);
        if ($parts === false || $parts === []) {
            return null;
        }

        $isHistory = false;
        $forceText = false;
        $dateTokens = [];

        foreach ($parts as $p) {
            $lp = mb_strtolower($p);
            if (in_array($lp, ['text', 'txt', '文字'], true)) {
                $forceText = true;

                continue;
            }
            if (in_array($lp, $historyKeywords, true)) {
                $isHistory = true;

                continue;
            }
            $dateTokens[] = $p;
        }

        $datePart = $dateTokens !== [] ? implode(' ', $dateTokens) : null;

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $startDate = null;
        $endDate = null;
        $isRange = false;

        if ($datePart !== null) {
            $ld = mb_strtolower($datePart);

            // 1. 相對區間：近N天 / Nd / Ndays / 一週 / 一個月 / 本週 / 上週 / 本月 / 上月
            if (preg_match('/^(?:近|過去)?\s*(\d{1,3})\s*(?:天|日|d|days)$/iu', $ld, $m)) {
                $days = (int) $m[1];
                if ($days >= 1) {
                    $startDate = $today->subDays($days - 1);
                    $endDate = $today;
                    $isRange = $days > 1;
                    $isHistory = true;
                }
            } elseif ($isHistory && preg_match('/^(\d{1,3})$/', $ld, $m)) {
                $days = (int) $m[1];
                if ($days >= 1) {
                    $startDate = $today->subDays($days - 1);
                    $endDate = $today;
                    $isRange = $days > 1;
                }
            } elseif (in_array($ld, ['本週', '本周', 'this week', 'week', 'thisweek'], true)) {
                $startDate = $today->startOfWeek();
                $endDate = $today;
                $isRange = true;
                $isHistory = true;
            } elseif (in_array($ld, ['上週', '上周', 'last week', 'lastweek'], true)) {
                $startDate = $today->subWeek()->startOfWeek();
                $endDate = $today->subWeek()->endOfWeek();
                $isRange = true;
                $isHistory = true;
            } elseif (in_array($ld, ['本月', 'this month', 'month', 'thismonth'], true)) {
                $startDate = $today->startOfMonth();
                $endDate = $today;
                if ($startDate->diffInDays($endDate) + 1 > self::MAX_HISTORY_DAYS) {
                    $startDate = $endDate->subDays(self::MAX_HISTORY_DAYS - 1);
                }
                $isRange = true;
                $isHistory = true;
            } elseif (in_array($ld, ['上月', 'last month', 'lastmonth'], true)) {
                $endDate = $today->subMonth()->endOfMonth();
                $startDate = $today->subMonth()->startOfMonth();
                if ($startDate->diffInDays($endDate) + 1 > self::MAX_HISTORY_DAYS) {
                    $startDate = $endDate->subDays(self::MAX_HISTORY_DAYS - 1);
                }
                $isRange = true;
                $isHistory = true;
            } elseif (in_array($ld, ['一週', '一周'], true)) {
                $startDate = $today->subDays(6);
                $endDate = $today;
                $isRange = true;
                $isHistory = true;
            } elseif (in_array($ld, ['一個月', '一个月'], true)) {
                $startDate = $today->subDays(self::MAX_HISTORY_DAYS - 1);
                $endDate = $today;
                $isRange = true;
                $isHistory = true;
            }

            // 2. 兩日期分隔符區間 (09-01~09-06, 9/1~9/6, 0901~0906, 09-01至09-06, 09-01到09-06, 09-01..09-06, 09-01 - 09-06)
            if ($startDate === null) {
                if (preg_match('/^(.+?)(?:\s*(?:~|～|至|到|\.\.|\s-\s)\s*)(.+)$/u', $datePart, $rm)) {
                    $d1 = $this->parseDateString(trim($rm[1]), $today, $timezone);
                    $d2 = $this->parseDateString(trim($rm[2]), $today, $timezone);
                    if ($d1 !== null && $d2 !== null) {
                        if ($d2->lt($d1)) {
                            [$d1, $d2] = [$d2, $d1];
                        }
                        $startDate = $d1;
                        $endDate = $d2;
                        $isRange = ! $d1->isSameDay($d2);
                        $isHistory = true;
                    }
                } elseif (preg_match('/^(\d{1,2}[-\/\.]\d{1,2})-(\d{1,2}[-\/\.]\d{1,2})$/', $datePart, $rm)) {
                    $d1 = $this->parseDateString(trim($rm[1]), $today, $timezone);
                    $d2 = $this->parseDateString(trim($rm[2]), $today, $timezone);
                    if ($d1 !== null && $d2 !== null) {
                        if ($d2->lt($d1)) {
                            [$d1, $d2] = [$d2, $d1];
                        }
                        $startDate = $d1;
                        $endDate = $d2;
                        $isRange = ! $d1->isSameDay($d2);
                        $isHistory = true;
                    }
                } elseif (preg_match('/^(\d{4})-(\d{4})$/', $datePart, $rm)) {
                    $d1 = $this->parseDateString(trim($rm[1]), $today, $timezone);
                    $d2 = $this->parseDateString(trim($rm[2]), $today, $timezone);
                    if ($d1 !== null && $d2 !== null) {
                        if ($d2->lt($d1)) {
                            [$d1, $d2] = [$d2, $d1];
                        }
                        $startDate = $d1;
                        $endDate = $d2;
                        $isRange = ! $d1->isSameDay($d2);
                        $isHistory = true;
                    }
                }
            }

            // 3. 單一日期 (09-06, 9/6, 0906, 9.6, 9月6日, 2026-09-06, 昨天, 今天等)
            if ($startDate === null) {
                $single = $this->parseDateString($datePart, $today, $timezone);
                if ($single !== null) {
                    $startDate = $single;
                    $endDate = $single;
                    $isRange = false;
                    $isHistory = true;
                }
            }
        }

        // 4. 若未指定日期但包含 history/record 關鍵字，預設為今天
        if ($startDate === null && $datePart === null && $isHistory) {
            $startDate = $today;
            $endDate = $today;
            $isRange = false;
        }

        if (! $isHistory || $startDate === null || $endDate === null) {
            return null;
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_range' => $isRange,
            'date' => $startDate,
            'force_text' => $forceText,
        ];
    }

    public function handleBetHistoryReply(
        CarbonImmutable $startDate,
        CarbonImmutable|bool|null $endDateOrForceText = null,
        bool|string $forceTextOrTimezone = false,
        string $timezone = 'Asia/Taipei'
    ): LineBotReply {
        if ($endDateOrForceText instanceof CarbonImmutable) {
            $endDate = $endDateOrForceText;
            $forceText = (bool) $forceTextOrTimezone;
        } elseif (is_bool($endDateOrForceText)) {
            $endDate = $startDate;
            $forceText = $endDateOrForceText;
            $timezone = is_string($forceTextOrTimezone) ? $forceTextOrTimezone : $timezone;
        } else {
            $endDate = $startDate;
            $forceText = (bool) $forceTextOrTimezone;
        }

        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $days = (int) $startDate->startOfDay()->diffInDays($endDate->startOfDay()) + 1;
        if ($days > self::MAX_HISTORY_DAYS) {
            return new LineBotReply(sprintf('查詢區間最多只能查詢 %d 天，請縮小日期範圍再試。', self::MAX_HISTORY_DAYS));
        }

        try {
            $rawBets = $this->getBetsForRange($startDate, $endDate);
            $pnlData = $this->calculateDatePnL($rawBets, $timezone);
            $bets = $pnlData['bets'];
            $summary = $pnlData['summary'];

            $balance = null;
            try {
                $balance = $this->getUsdtBalance();
            } catch (Throwable $e) {
                Log::warning('Stake USDT balance fetch failed during history reply.', [
                    'error' => $e->getMessage(),
                ]);
            }

            $text = $this->formatBetHistoryMessage($startDate, $endDate, $bets, $summary, $balance, $timezone);
            $linkUrl = 'https://stake.com/zh/my-bets/sports';

            $imageData = null;
            if (! $forceText) {
                $imageData = $this->buildBetHistoryImageData($startDate, $endDate, $bets, $summary, $balance, $timezone);
            }

            return new LineBotReply($text, $linkUrl, $imageData);
        } catch (RequestException $exception) {
            return $this->handleRequestException($exception);
        } catch (ConnectionException $exception) {
            return $this->handleConnectionException($exception);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Stake API bet history processing failed.', [
                'type' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return new LineBotReply('目前無法取得 Stake 投注紀錄，請稍後再試。');
        }
    }

    public function handleBalanceChartReply(string $argument = ''): LineBotReply
    {
        $token = trim((string) config('services.stake.access_token'));

        if ($token === '') {
            return new LineBotReply('尚未設定 Stake Access Token，請在環境變數中設定 STAKE_ACCESS_TOKEN。');
        }

        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $params = $this->parseBalanceArgument($argument, $timezone);

        if ($params['is_help']) {
            return new LineBotReply(
                "指令格式：\n!balance 或 !bal 或 !水位 [天數]\n查詢 Stake 資金水位的長條圖（根據每一單結盤時間點繪製）。\n\n預設回傳近 3 天，最多可查詢 30 天。\n\n支援範例：\n・!balance（預設 3 天）\n・!bal 7d 或 !bal 7天\n・!水位 30d\n・加 text 查看純文字（例如 !balance 3d text）"
            );
        }

        try {
            $cacheKey = sprintf('stake:balance_chart_data:days_%d:tz_%s', $params['days'], $timezone);
            $cachedData = null;
            try {
                $cachedData = Cache::get($cacheKey);
            } catch (Throwable) {
                // non-blocking cache retrieval
            }

            if (is_array($cachedData) && isset($cachedData['bets'])) {
                $settledBets = $cachedData['bets'];
                $balance = $cachedData['balance'];
            } else {
                $data = $this->getSettledBetsAndBalanceForDays($params['days'], $timezone);
                $settledBets = $data['bets'];
                $balance = $data['balance'];

                try {
                    $ttlSeconds = (int) config('services.stake.chart_cache_ttl', 30);
                    if ($ttlSeconds > 0) {
                        Cache::put($cacheKey, ['bets' => $settledBets, 'balance' => $balance], now()->addSeconds($ttlSeconds));
                    }
                } catch (Throwable) {
                    // non-blocking cache storage
                }
            }

            $chartData = $this->calculateBalanceHistory(
                $settledBets,
                $balance,
                $timezone,
                $params['days'],
                $params['start_date'],
                $params['end_date']
            );

            $text = $this->formatBalanceChartMessage(
                $params['days'],
                $params['start_date'],
                $params['end_date'],
                $chartData,
                $balance,
                $params['exceeded_max'],
                $timezone
            );

            $linkUrl = 'https://stake.com/zh/my-bets/sports';

            $imageData = null;
            if (! $params['force_text']) {
                $imageData = $this->buildBalanceChartImageData(
                    $params['days'],
                    $params['start_date'],
                    $params['end_date'],
                    $chartData,
                    $balance,
                    $timezone
                );
            }

            return new LineBotReply($text, $linkUrl, $imageData);
        } catch (RequestException $exception) {
            return $this->handleRequestException($exception);
        } catch (ConnectionException $exception) {
            return $this->handleConnectionException($exception);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Stake API balance chart processing failed.', [
                'type' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return new LineBotReply('目前無法取得 Stake 資金水位資訊，請稍後再試。');
        }
    }

    /**
     * @return array{
     *     days: int,
     *     start_date: CarbonImmutable,
     *     end_date: CarbonImmutable,
     *     force_text: bool,
     *     is_help: bool,
     *     exceeded_max: bool
     * }
     */
    public function parseBalanceArgument(string $argument, string $timezone = 'Asia/Taipei'): array
    {
        $raw = trim($argument);
        $lower = mb_strtolower($raw);

        if ($lower === 'help') {
            $now = CarbonImmutable::now($timezone);

            return [
                'days' => 3,
                'start_date' => $now->subDays(2)->startOfDay(),
                'end_date' => $now->endOfDay(),
                'force_text' => false,
                'is_help' => true,
                'exceeded_max' => false,
            ];
        }

        $forceText = false;
        if (preg_match('/\b(text|txt|文字)\b/iu', $raw)) {
            $forceText = true;
            $raw = trim(preg_replace('/\b(text|txt|文字)\b/iu', '', $raw));
        }

        $days = 3;
        $exceededMax = false;

        if (preg_match('/(?:近\s*)?(\d+)\s*(?:d|天|日)?/iu', $raw, $m)) {
            $parsedDays = (int) $m[1];
            if ($parsedDays > 30) {
                $days = 30;
                $exceededMax = true;
            } elseif ($parsedDays < 1) {
                $days = 1;
            } else {
                $days = $parsedDays;
            }
        }

        $now = CarbonImmutable::now($timezone);
        $startDate = $now->subDays($days - 1)->startOfDay();
        $endDate = $now->endOfDay();

        return [
            'days' => $days,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'force_text' => $forceText,
            'is_help' => false,
            'exceeded_max' => $exceededMax,
        ];
    }

    /**
     * @return array{
     *     bets: array<int, array<string, mixed>>,
     *     balance: array{available: float, vault: float, total: float}|null
     * }
     */
    public function getSettledBetsAndBalanceForDays(int $days, string $timezone = 'Asia/Taipei'): array
    {
        $days = min(30, max(1, $days));
        $now = CarbonImmutable::now($timezone);
        $startDay = $now->subDays($days - 1)->startOfDay();
        $endDay = $now->endOfDay();

        // 1. 優先嘗試 GraphQL 批次查詢（在單一 HTTP 請求中合併多頁注單與餘額）
        try {
            $batched = $this->fetchBatchedBetsAndBalance($days, $startDay, $endDay, $timezone);
            if ($batched !== null) {
                return $batched;
            }
        } catch (Throwable $e) {
            Log::debug('Stake GraphQL batched balance query failed, falling back to sequential fetch.', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. 降級回退至既有的序列查詢
        $settledBets = $this->getSettledBetsForDays($days, $timezone);
        $balance = null;
        try {
            $balance = $this->getUsdtBalance();
        } catch (Throwable $e) {
            Log::warning('Stake USDT balance fetch failed during sequential fallback.', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'bets' => $settledBets,
            'balance' => $balance,
        ];
    }

    /**
     * @return array{
     *     bets: array<int, array<string, mixed>>,
     *     balance: array{available: float, vault: float, total: float}|null
     * }|null
     */
    public function fetchBatchedBetsAndBalance(
        int $days,
        CarbonImmutable $startDay,
        CarbonImmutable $endDay,
        string $timezone = 'Asia/Taipei'
    ): ?array {
        $pageCount = match (true) {
            $days <= 3 => 2,
            $days <= 7 => 2,
            $days <= 14 => 3,
            default => 4,
        };

        $fields = '';
        for ($i = 0; $i < $pageCount; $i++) {
            $offset = $i * 50;
            $fields .= "    p{$i}: sportBetList(limit: 50, offset: {$offset}) {\n      id\n      iid\n      bet {\n        __typename\n        ...SportBetPreview_SportBet\n      }\n    }\n";
        }

        $query = "query FetchBatchedBalanceAndBets {\n  user {\n    id\n    balances {\n      available {\n        amount\n        currency\n      }\n      vault {\n        amount\n        currency\n      }\n    }\n{$fields}  }\n}\n".self::SPORT_BET_FRAGMENTS;

        $response = $this->sendGraphQLRequest('FetchBatchedBalanceAndBets', $query, []);

        $userData = $response['data']['user'] ?? null;
        if (! is_array($userData) || ! isset($userData['p0'])) {
            return null;
        }

        $balance = isset($userData['balances']) && is_array($userData['balances'])
            ? $this->extractUsdtBalance($userData['balances'])
            : null;

        $seenIds = [];
        $settledBets = [];
        $lastPageFull = false;
        $allTailOlder = false;

        for ($i = 0; $i < $pageCount; $i++) {
            $rawList = $userData["p{$i}"] ?? [];
            if (! is_array($rawList) || $rawList === []) {
                $lastPageFull = false;
                break;
            }

            $list = [];
            foreach ($rawList as $item) {
                if (isset($item['bet']) && is_array($item['bet'])) {
                    $bet = $item['bet'];
                    if (isset($item['iid']) && ! isset($bet['bet']['iid'])) {
                        $bet['bet'] = ['iid' => $item['iid'], '__typename' => 'Bet'];
                    }
                    $list[] = $bet;
                }
            }

            $lastPageFull = count($list) >= 50;

            foreach ($list as $bet) {
                $rawStatus = mb_strtolower((string) ($bet['status'] ?? 'pending'));
                $isActive = (bool) ($bet['active'] ?? false);

                if ($isActive || $rawStatus === 'confirmed' || $rawStatus === 'pending') {
                    continue;
                }

                $settledTime = $this->getBetSettlementTime($bet, $timezone);
                if ($settledTime->greaterThanOrEqualTo($startDay) && $settledTime->lessThanOrEqualTo($endDay)) {
                    $id = (string) ($bet['id'] ?? '');
                    if ($id !== '' && ! isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        $settledBets[] = $bet;
                    }
                }
            }

            $olderTailCount = 0;
            for ($j = count($list) - 1; $j >= 0; $j--) {
                $b = $list[$j];
                $sTime = $this->getBetSettlementTime($b, $timezone);
                $cTimeStr = (string) ($b['createdAt'] ?? '');
                $cTime = $cTimeStr !== ''
                    ? CarbonImmutable::parse($cTimeStr)->setTimezone($timezone)
                    : $sTime;

                if ($sTime->lt($startDay) && $cTime->lt($startDay)) {
                    $olderTailCount++;
                } else {
                    break;
                }
            }

            if ($olderTailCount >= 10 || count($list) < 50) {
                $allTailOlder = true;
                break;
            }
        }

        // 若批次取回的最後一頁已滿 50 筆且尾端仍未抵達歷史早停，則順延序列獲取後續頁數
        if ($lastPageFull && ! $allTailOlder) {
            $offset = $pageCount * 50;
            for ($p = 0; $p < 10; $p++) {
                $more = $this->getSportBetList(50, $offset);
                if ($more === []) {
                    break;
                }
                foreach ($more as $bet) {
                    $rawStatus = mb_strtolower((string) ($bet['status'] ?? 'pending'));
                    $isActive = (bool) ($bet['active'] ?? false);
                    if ($isActive || $rawStatus === 'confirmed' || $rawStatus === 'pending') {
                        continue;
                    }
                    $settledTime = $this->getBetSettlementTime($bet, $timezone);
                    if ($settledTime->greaterThanOrEqualTo($startDay) && $settledTime->lessThanOrEqualTo($endDay)) {
                        $id = (string) ($bet['id'] ?? '');
                        if ($id !== '' && ! isset($seenIds[$id])) {
                            $seenIds[$id] = true;
                            $settledBets[] = $bet;
                        }
                    }
                }

                $olderTailCount = 0;
                for ($j = count($more) - 1; $j >= 0; $j--) {
                    $b = $more[$j];
                    $sTime = $this->getBetSettlementTime($b, $timezone);
                    $cTimeStr = (string) ($b['createdAt'] ?? '');
                    $cTime = $cTimeStr !== ''
                        ? CarbonImmutable::parse($cTimeStr)->setTimezone($timezone)
                        : $sTime;
                    if ($sTime->lt($startDay) && $cTime->lt($startDay)) {
                        $olderTailCount++;
                    } else {
                        break;
                    }
                }
                if ($olderTailCount >= 10 || count($more) < 50) {
                    break;
                }
                $offset += 50;
            }
        }

        usort($settledBets, function (array $a, array $b) use ($timezone): int {
            $tA = $this->getBetSettlementTime($a, $timezone)->getTimestamp();
            $tB = $this->getBetSettlementTime($b, $timezone)->getTimestamp();

            if ($tA === $tB) {
                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }

            return $tA <=> $tB;
        });

        return [
            'bets' => $settledBets,
            'balance' => $balance,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettledBetsForDays(int $days, string $timezone = 'Asia/Taipei'): array
    {
        $days = min(30, max(1, $days));
        $now = CarbonImmutable::now($timezone);
        $startDay = $now->subDays($days - 1)->startOfDay();
        $endDay = $now->endOfDay();

        $offset = 0;
        $limit = 50;
        $maxPages = 20;
        $seenIds = [];
        $settledBets = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $list = $this->getSportBetList($limit, $offset);
            if ($list === []) {
                break;
            }

            $consecutiveOlderCount = 0;
            foreach ($list as $bet) {
                $rawStatus = mb_strtolower((string) ($bet['status'] ?? 'pending'));
                $isActive = (bool) ($bet['active'] ?? false);

                if ($isActive || $rawStatus === 'confirmed' || $rawStatus === 'pending') {
                    continue;
                }

                $settledTime = $this->getBetSettlementTime($bet, $timezone);
                $createdTimeStr = (string) ($bet['createdAt'] ?? '');
                $createdTime = $createdTimeStr !== ''
                    ? CarbonImmutable::parse($createdTimeStr)->setTimezone($timezone)
                    : $settledTime;

                if ($settledTime->greaterThanOrEqualTo($startDay) && $settledTime->lessThanOrEqualTo($endDay)) {
                    $id = (string) ($bet['id'] ?? '');
                    if ($id !== '' && ! isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        $settledBets[] = $bet;
                    }
                } elseif ($settledTime->lt($startDay) && $createdTime->lt($startDay)) {
                    $consecutiveOlderCount++;
                }
            }

            $olderTailCount = 0;
            for ($i = count($list) - 1; $i >= 0; $i--) {
                $b = $list[$i];
                $sTime = $this->getBetSettlementTime($b, $timezone);
                $cTimeStr = (string) ($b['createdAt'] ?? '');
                $cTime = $cTimeStr !== ''
                    ? CarbonImmutable::parse($cTimeStr)->setTimezone($timezone)
                    : $sTime;

                if ($sTime->lt($startDay) && $cTime->lt($startDay)) {
                    $olderTailCount++;
                } else {
                    break;
                }
            }

            if ($olderTailCount >= 10 || count($list) < $limit) {
                break;
            }

            $offset += $limit;
        }

        usort($settledBets, function (array $a, array $b) use ($timezone): int {
            $tA = $this->getBetSettlementTime($a, $timezone)->getTimestamp();
            $tB = $this->getBetSettlementTime($b, $timezone)->getTimestamp();

            if ($tA === $tB) {
                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }

            return $tA <=> $tB;
        });

        return $settledBets;
    }

    /**
     * @param  array<string, mixed>  $bet
     */
    public function getBetSettlementTime(array $bet, string $timezone = 'Asia/Taipei'): CarbonImmutable
    {
        $rawStatus = mb_strtolower((string) ($bet['status'] ?? ''));

        $timeStr = (string) (
            $bet['settledAt']
            ?? $bet['settled_at']
            ?? ($rawStatus === 'cashout' ? ($bet['cashouts'][0]['createdAt'] ?? null) : null)
            ?? $bet['updatedAt']
            ?? $bet['updated_at']
            ?? ($bet['cashouts'][0]['createdAt'] ?? null)
            ?? ($bet['cashouts'][0]['created_at'] ?? null)
            ?? ($bet['adjustments'][0]['updatedAt'] ?? null)
            ?? ($bet['adjustments'][0]['created_at'] ?? null)
            ?? ($bet['createdAt'] ?? '')
        );

        if ($timeStr === '') {
            return CarbonImmutable::now($timezone);
        }

        return CarbonImmutable::parse($timeStr)->setTimezone($timezone);
    }

    /**
     * @param  array<int, array<string, mixed>>  $settledBets
     * @param  array{available: float, vault: float, total: float}|null  $balance
     * @return array{
     *     bars: array<int, array<string, mixed>>,
     *     start_balance: float,
     *     current_balance: float,
     *     net_change: float,
     *     max_watermark: float,
     *     min_watermark: float,
     *     total_bets: int,
     *     won_count: int,
     *     lost_count: int,
     *     cashout_count: int,
     *     void_count: int,
     *     win_rate: float,
     *     roi: float,
     *     total_staked: float,
     *     total_payout: float,
     *     aggregation?: string,
     *     bet_bars?: array<int, array<string, mixed>>,
     *     daily_bars?: array<int, array<string, mixed>>
     * }
     */
    public function calculateBalanceHistory(
        array $settledBets,
        ?array $balance = null,
        string $timezone = 'Asia/Taipei',
        ?int $days = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null
    ): array {
        $standardizedBets = [];
        foreach ($settledBets as $bet) {
            $amount = (float) ($bet['amount'] ?? 0);
            $payout = (float) ($bet['payout'] ?? 0);
            $currency = mb_strtoupper((string) ($bet['currency'] ?? 'USDT'));
            $rawStatus = mb_strtolower((string) ($bet['status'] ?? 'pending'));
            $potentialMultiplier = (float) ($bet['potentialMultiplier'] ?? 1);

            if ($rawStatus === 'cashout') {
                $status = 'cashout';
                $statusLabel = '已兌現';
                $profit = $payout - $amount;
            } elseif (in_array($rawStatus, ['cancelled', 'void', 'refund', 'refunded'], true) || (abs($payout - $amount) < 0.001 && $rawStatus === 'settled')) {
                $status = 'void';
                $statusLabel = '退款';
                $profit = 0.0;
            } elseif ($rawStatus === 'settled' && $payout > 0) {
                $status = 'won';
                $statusLabel = '獲勝';
                $profit = $payout - $amount;
            } else {
                $status = 'lost';
                $statusLabel = '未中獎';
                $profit = -$amount;
            }

            $settledTime = $this->getBetSettlementTime($bet, $timezone);

            $outcomes = is_array($bet['outcomes'] ?? null) ? $bet['outcomes'] : [];
            $legCount = count($outcomes);
            $isParlay = $legCount > 1;

            $sportName = '';
            $matchName = '';
            if ($legCount === 1 && isset($outcomes[0])) {
                $fixture = is_array($outcomes[0]['fixture'] ?? null) ? $outcomes[0]['fixture'] : [];
                $tournament = is_array($fixture['tournament'] ?? null) ? $fixture['tournament'] : [];
                $category = is_array($tournament['category'] ?? null) ? $tournament['category'] : [];
                $sport = is_array($category['sport'] ?? null) ? $category['sport'] : [];
                $sportName = ChineseConverter::toTraditional((string) ($sport['name'] ?? ''));
                $matchName = ChineseConverter::toTraditional((string) ($fixture['name'] ?? ''));
            } elseif ($isParlay) {
                $matchName = "{$legCount} 關串關";
            }

            $iid = (string) ($bet['bet']['iid'] ?? ($bet['iid'] ?? ''));
            if ($iid !== '' && ! str_starts_with($iid, '#')) {
                $iid = '#'.preg_replace('/^sport:/', '', $iid);
            }

            $standardizedBets[] = [
                'id' => (string) ($bet['id'] ?? ''),
                'iid' => $iid,
                'amount' => $amount,
                'payout' => $payout,
                'profit' => $profit,
                'currency' => $currency,
                'potential_multiplier' => $potentialMultiplier,
                'status' => $status,
                'status_label' => $statusLabel,
                'is_parlay' => $isParlay,
                'leg_count' => $legCount,
                'settled_at' => $settledTime->format('m/d H:i'),
                'settled_date' => $settledTime->format('m/d'),
                'settled_ymd' => $settledTime->format('Y-m-d'),
                'settled_time' => $settledTime->format('H:i'),
                'settled_timestamp' => $settledTime->getTimestamp(),
                'sport_name' => $sportName,
                'match_name' => $matchName,
            ];
        }

        usort($standardizedBets, function (array $a, array $b): int {
            if ($a['settled_timestamp'] === $b['settled_timestamp']) {
                return strcmp($a['id'], $b['id']);
            }

            return $a['settled_timestamp'] <=> $b['settled_timestamp'];
        });

        $count = count($standardizedBets);
        $currentTotal = $balance !== null ? (float) ($balance['total'] ?? 0.0) : null;

        $balances = [];
        if ($currentTotal !== null) {
            $running = $currentTotal;
            for ($i = $count - 1; $i >= 0; $i--) {
                $balances[$i] = $running;
                $running -= $standardizedBets[$i]['profit'];
            }
            $startBalance = $running;
            $currentBalance = $currentTotal;
        } else {
            $startBalance = 0.0;
            $running = 0.0;
            for ($i = 0; $i < $count; $i++) {
                $running += $standardizedBets[$i]['profit'];
                $balances[$i] = $running;
            }
            $currentBalance = $running;
        }

        $wonCount = 0;
        $lostCount = 0;
        $cashoutCount = 0;
        $voidCount = 0;
        $totalStaked = 0.0;
        $totalPayout = 0.0;
        $maxWatermark = $startBalance;
        $minWatermark = $startBalance;
        $bars = [];

        foreach ($standardizedBets as $idx => $sBet) {
            $b = $balances[$idx];
            if ($b > $maxWatermark) {
                $maxWatermark = $b;
            }
            if ($b < $minWatermark) {
                $minWatermark = $b;
            }

            $totalStaked += $sBet['amount'];
            $totalPayout += $sBet['payout'];

            match ($sBet['status']) {
                'won' => $wonCount++,
                'lost' => $lostCount++,
                'cashout' => $cashoutCount++,
                'void' => $voidCount++,
                default => null,
            };

            $profitFormatted = ($sBet['profit'] >= 0 ? '+' : '').$this->formatBalanceAmount($sBet['profit']);

            $bars[] = array_merge($sBet, [
                'index' => $idx + 1,
                'balance' => $b,
                'balance_formatted' => $this->formatBalanceAmount($b),
                'profit_formatted' => $profitFormatted,
            ]);
        }

        $decided = $wonCount + $lostCount;
        $winRate = $decided > 0 ? (($wonCount / $decided) * 100) : 0.0;
        $netChange = $currentBalance - $startBalance;
        $roi = $totalStaked > 0 ? (($netChange / $totalStaked) * 100) : 0.0;

        $aggregation = ($days !== null && $days > 7) ? 'daily' : 'bet';
        $dailyBars = [];
        if ($startDate !== null && $endDate !== null) {
            $dailyBars = $this->aggregateDailyBars(
                $bars,
                $startBalance,
                $currentBalance,
                $startDate,
                $endDate,
                $timezone
            );
        }

        $displayBars = $aggregation === 'daily' ? $dailyBars : $bars;

        return [
            'aggregation' => $aggregation,
            'bars' => $displayBars,
            'bet_bars' => $bars,
            'daily_bars' => $dailyBars,
            'start_balance' => $startBalance,
            'current_balance' => $currentBalance,
            'net_change' => $netChange,
            'max_watermark' => $maxWatermark,
            'min_watermark' => $minWatermark,
            'total_bets' => $count,
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'cashout_count' => $cashoutCount,
            'void_count' => $voidCount,
            'win_rate' => $winRate,
            'roi' => $roi,
            'total_staked' => $totalStaked,
            'total_payout' => $totalPayout,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<int, array<string, mixed>>
     */
    public function aggregateDailyBars(
        array $bars,
        float $startBalance,
        float $currentBalance,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        string $timezone = 'Asia/Taipei'
    ): array {
        if ($bars === []) {
            return [];
        }

        $betsByDate = [];
        foreach ($bars as $b) {
            $ymd = (string) ($b['settled_ymd'] ?? '');
            if ($ymd !== '') {
                $betsByDate[$ymd][] = $b;
            }
        }

        $dates = [];
        $cursor = $startDate->setTimezone($timezone)->startOfDay();
        $endDay = $endDate->setTimezone($timezone)->startOfDay();

        while ($cursor <= $endDay) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        $dailyBars = [];
        $runningWatermark = $startBalance;
        $dayIndex = 1;

        foreach ($dates as $ymd) {
            $carbonDate = CarbonImmutable::parse($ymd, $timezone);
            $dayBets = $betsByDate[$ymd] ?? [];
            $dayBetsCount = count($dayBets);

            if ($dayBetsCount > 0) {
                $dayProfit = 0.0;
                $dayStaked = 0.0;
                $dayPayout = 0.0;
                $dayWonCount = 0;
                $dayLostCount = 0;
                $dayCashoutCount = 0;
                $dayVoidCount = 0;

                foreach ($dayBets as $b) {
                    $dayProfit += (float) ($b['profit'] ?? 0.0);
                    $dayStaked += (float) ($b['amount'] ?? 0.0);
                    $dayPayout += (float) ($b['payout'] ?? 0.0);
                    match ($b['status'] ?? '') {
                        'won' => $dayWonCount++,
                        'lost' => $dayLostCount++,
                        'cashout' => $dayCashoutCount++,
                        'void' => $dayVoidCount++,
                        default => null,
                    };
                }

                $lastBet = end($dayBets);
                $closingBalance = (float) ($lastBet['balance'] ?? $runningWatermark);
                $runningWatermark = $closingBalance;

                if ($dayProfit > 0.001) {
                    $status = 'won';
                    $statusLabel = '當日盈利';
                } elseif ($dayProfit < -0.001) {
                    $status = 'lost';
                    $statusLabel = '當日虧損';
                } elseif ($dayCashoutCount > 0) {
                    $status = 'cashout';
                    $statusLabel = '當日兌現';
                } else {
                    $status = 'void';
                    $statusLabel = '當日持平';
                }
            } else {
                $dayProfit = 0.0;
                $dayStaked = 0.0;
                $dayPayout = 0.0;
                $dayWonCount = 0;
                $dayLostCount = 0;
                $dayCashoutCount = 0;
                $dayVoidCount = 0;
                $closingBalance = $runningWatermark;
                $status = 'flat';
                $statusLabel = '無結盤';
            }

            $profitFormatted = ($dayProfit >= 0.001 ? '+' : '').$this->formatBalanceAmount($dayProfit);

            $dailyBars[] = [
                'id' => 'day-'.$ymd,
                'index' => $dayIndex,
                'date' => $ymd,
                'settled_date' => $carbonDate->format('m/d'),
                'settled_time' => $dayBetsCount > 0 ? "{$dayBetsCount} 筆" : '-',
                'day_of_week' => $this->chineseDayOfWeek($carbonDate->dayOfWeek),
                'balance' => $closingBalance,
                'balance_formatted' => $this->formatBalanceAmount($closingBalance),
                'profit' => $dayProfit,
                'profit_formatted' => $profitFormatted,
                'amount' => $dayStaked,
                'payout' => $dayPayout,
                'status' => $status,
                'status_label' => $statusLabel,
                'total_bets' => $dayBetsCount,
                'won_count' => $dayWonCount,
                'lost_count' => $dayLostCount,
                'cashout_count' => $dayCashoutCount,
                'void_count' => $dayVoidCount,
            ];

            $dayIndex++;
        }

        return $dailyBars;
    }

    private function chineseDayOfWeek(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => '日',
            1 => '一',
            2 => '二',
            3 => '三',
            4 => '四',
            5 => '五',
            6 => '六',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $chartData
     * @param  array{available: float, vault: float, total: float}|null  $balance
     */
    public function formatBalanceChartMessage(
        int $days,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $chartData,
        ?array $balance = null,
        bool $exceededMax = false,
        string $timezone = 'Asia/Taipei'
    ): string {
        $aggregation = $chartData['aggregation'] ?? ($days > 7 ? 'daily' : 'bet');
        $modeDesc = $aggregation === 'daily' ? '依每日收盤水位聚合' : '依結盤時間點';

        $lines = [
            'Stake 體育投注｜資金水位走勢',
            sprintf('區間｜%s ~ %s（近 %d 天）・%s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $days, $modeDesc),
        ];

        if ($exceededMax) {
            $lines[] = '提示｜查詢上限為 30 天，已自動為您呈現近 30 天數據。';
        }

        $lines[] = '時間基準｜台灣時間';
        $lines[] = '';
        $lines[] = '【💰 資金水位總覽】';

        if ($balance !== null) {
            $lines[] = '・目前水位：'.$this->formatBalanceLine($balance);
        } else {
            $lines[] = sprintf('・目前水位：%s USDT', $this->formatBalanceAmount($chartData['current_balance']));
        }

        $lines[] = sprintf('・期初水位：%s USDT', $this->formatBalanceAmount($chartData['start_balance']));

        $netChange = $chartData['net_change'];
        $netSign = $netChange > 0.001 ? '+' : '';
        $tag = match (true) {
            $netChange > 0.001 => '▲ 盈利',
            $netChange < -0.001 => '▼ 虧損',
            default => '持平',
        };
        $lines[] = sprintf('・區間損益：%s%s USDT（%s）', $netSign, $this->formatBalanceAmount($netChange), $tag);
        $lines[] = sprintf('・水位極值：最高 %s USDT / 最低 %s USDT', $this->formatBalanceAmount($chartData['max_watermark']), $this->formatBalanceAmount($chartData['min_watermark']));
        $lines[] = sprintf('・結盤戰績：%d 勝  %d 負（勝率 %.1f%%）', $chartData['won_count'], $chartData['lost_count'], $chartData['win_rate']);

        if ($aggregation === 'daily') {
            $dailyBars = $chartData['daily_bars'] ?? $chartData['bars'];
            if ($dailyBars === [] || ($chartData['total_bets'] ?? 0) === 0) {
                $lines[] = '';
                $lines[] = sprintf('近 %d 天內查無結盤之體育注單。', $days);
            } else {
                $lines[] = '';
                $lines[] = sprintf('【📊 每日收盤水位明細（共 %d 天，%d 筆結盤）】', count($dailyBars), $chartData['total_bets']);

                foreach ($dailyBars as $b) {
                    $statusEmoji = match ($b['status'] ?? '') {
                        'won' => '📈',
                        'lost' => '📉',
                        'cashout' => '💰',
                        'void' => '⚪',
                        default => '➖',
                    };

                    if (($b['total_bets'] ?? 0) > 0) {
                        $lines[] = sprintf(
                            '%d. %s（%s）%s %s',
                            $b['index'],
                            $b['settled_date'],
                            $b['day_of_week'] ?? '',
                            $b['status_label'] ?? '',
                            $statusEmoji
                        );
                        $lines[] = sprintf(
                            '   結盤 %d 筆（%d勝 %d負）｜當日損益：%s USDT',
                            $b['total_bets'],
                            $b['won_count'] ?? 0,
                            $b['lost_count'] ?? 0,
                            $b['profit_formatted']
                        );
                        $lines[] = sprintf(
                            '   日終水位：%s USDT',
                            $b['balance_formatted']
                        );
                    } else {
                        $lines[] = sprintf(
                            '%d. %s（%s）無結盤｜日終水位：%s USDT',
                            $b['index'],
                            $b['settled_date'],
                            $b['day_of_week'] ?? '',
                            $b['balance_formatted']
                        );
                    }
                }
            }
        } else {
            $bars = $chartData['bars'];
            if ($bars === []) {
                $lines[] = '';
                $lines[] = sprintf('近 %d 天內查無結盤之體育注單。', $days);
            } else {
                $lines[] = '';
                $lines[] = sprintf('【📊 結盤水位明細（共 %d 筆）】', count($bars));

                $displayBars = array_slice($bars, -20);
                if (count($bars) > 20) {
                    $lines[] = sprintf('（僅列出最近 20 筆結盤明細，前 %d 筆請參閱圖表）', count($bars) - 20);
                }

                foreach ($displayBars as $b) {
                    $statusEmoji = match ($b['status']) {
                        'won' => '🏆',
                        'lost' => '❌',
                        'cashout' => '💰',
                        'void' => '⚪',
                        default => '⏳',
                    };
                    $iidStr = $b['iid'] !== '' ? "｜{$b['iid']}" : '';
                    $lines[] = sprintf(
                        '%d. %s%s',
                        $b['index'],
                        $b['settled_at'],
                        $iidStr
                    );
                    $sportTag = $b['sport_name'] !== '' ? "【{$b['sport_name']}】" : '';
                    if ($b['match_name'] !== '') {
                        $lines[] = "   賽事：{$sportTag}{$b['match_name']}";
                    }
                    $lines[] = sprintf(
                        '   結果：%s %s（%s USDT）',
                        $b['status_label'],
                        $statusEmoji,
                        $b['profit_formatted']
                    );
                    $lines[] = sprintf(
                        '   結盤後水位：%s USDT',
                        $b['balance_formatted']
                    );
                }
            }
        }

        $lines[] = '';
        $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $chartData
     * @param  array{available: float, vault: float, total: float}|null  $balance
     * @return array<string, mixed>
     */
    public function buildBalanceChartImageData(
        int $days,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $chartData,
        ?array $balance = null,
        string $timezone = 'Asia/Taipei'
    ): array {
        $aggregation = $chartData['aggregation'] ?? ($days > 7 ? 'daily' : 'bet');
        $modeDesc = $aggregation === 'daily' ? '依每日收盤水位聚合繪製' : '依每單結盤時間繪製';

        return [
            'type' => 'balance_chart',
            'aggregation' => $aggregation,
            'title' => 'Stake 體育投注｜資金水位長條圖',
            'subtitle' => sprintf('區間｜%s ~ %s（近 %d 天）・%s', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $days, $modeDesc),
            'days' => $days,
            'current_balance_formatted' => $balance !== null ? $this->formatBalanceForImage($balance) : null,
            'summary' => [
                'current_balance' => $this->formatBalanceAmount($chartData['current_balance']).' USDT',
                'start_balance' => $this->formatBalanceAmount($chartData['start_balance']).' USDT',
                'net_change' => ($chartData['net_change'] >= 0 ? '+' : '').$this->formatBalanceAmount($chartData['net_change']).' USDT',
                'net_change_val' => $chartData['net_change'],
                'max_watermark' => $this->formatBalanceAmount($chartData['max_watermark']).' USDT',
                'min_watermark' => $this->formatBalanceAmount($chartData['min_watermark']).' USDT',
                'max_watermark_val' => $chartData['max_watermark'] ?? null,
                'min_watermark_val' => $chartData['min_watermark'] ?? null,
                'total_bets' => $chartData['total_bets'],
                'won_count' => $chartData['won_count'],
                'lost_count' => $chartData['lost_count'],
                'cashout_count' => $chartData['cashout_count'],
                'void_count' => $chartData['void_count'],
                'win_rate' => sprintf('%.1f%%', $chartData['win_rate']),
                'roi' => sprintf('%s%.1f%%', $chartData['roi'] >= 0 ? '+' : '', $chartData['roi']),
            ],
            'bars' => $chartData['bars'],
        ];
    }

    /**
     * @param  array<int, string>|null  $status
     * @return array<int, array<string, mixed>>
     */
    public function getSportBetList(int $limit = 50, int $offset = 0, ?array $status = null): array
    {
        $variables = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($status !== null && $status !== []) {
            $variables['status'] = $status;
        }

        $response = $this->sendGraphQLRequest(
            'FetchSportBetList',
            self::FETCH_SPORT_BET_LIST_QUERY,
            $variables
        );

        $items = $response['data']['user']['sportBetList'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $bets = [];
        foreach ($items as $item) {
            if (isset($item['bet']) && is_array($item['bet'])) {
                $bet = $item['bet'];
                if (isset($item['iid']) && ! isset($bet['bet']['iid'])) {
                    $bet['bet'] = ['iid' => $item['iid'], '__typename' => 'Bet'];
                }
                $bets[] = $bet;
            }
        }

        return $bets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBetsForDate(CarbonImmutable $targetDate): array
    {
        return $this->getBetsForRange($targetDate, $targetDate);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBetsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $startDay = $startDate->setTimezone($timezone)->startOfDay();
        $endDay = $endDate->setTimezone($timezone)->endOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $seenIds = [];
        $matchedBets = [];

        // 1. 若今天在查詢區間內，先嘗試包含進行中的即時注單
        if ($endDay->greaterThanOrEqualTo($today) && $startDay->lessThanOrEqualTo($today->endOfDay())) {
            try {
                $activeBets = $this->getActiveSportBets(50);
                foreach ($activeBets as $bet) {
                    $createdAtStr = (string) ($bet['createdAt'] ?? '');
                    if ($createdAtStr === '') {
                        continue;
                    }
                    $created = CarbonImmutable::parse($createdAtStr)->setTimezone($timezone);
                    if ($created->greaterThanOrEqualTo($startDay) && $created->lessThanOrEqualTo($endDay)) {
                        $id = (string) ($bet['id'] ?? '');
                        if ($id !== '' && ! isset($seenIds[$id])) {
                            $seenIds[$id] = true;
                            $matchedBets[] = $bet;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Failed fetching active bets for date range filter.', ['error' => $e->getMessage()]);
            }
        }

        // 2. 獲取已結算/歷史注單
        $offset = 0;
        $limit = 50;
        $maxPages = 10;

        for ($page = 0; $page < $maxPages; $page++) {
            $list = $this->getSportBetList($limit, $offset);
            if ($list === []) {
                break;
            }

            $hasOlderBets = false;
            foreach ($list as $bet) {
                $createdAtStr = (string) ($bet['createdAt'] ?? '');
                if ($createdAtStr === '') {
                    continue;
                }
                $created = CarbonImmutable::parse($createdAtStr)->setTimezone($timezone);

                if ($created->greaterThanOrEqualTo($startDay) && $created->lessThanOrEqualTo($endDay)) {
                    $id = (string) ($bet['id'] ?? '');
                    if ($id !== '' && ! isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        $matchedBets[] = $bet;
                    }
                } elseif ($created->lt($startDay)) {
                    $hasOlderBets = true;
                }
            }

            if ($hasOlderBets || count($list) < $limit) {
                break;
            }

            $offset += $limit;
        }

        usort($matchedBets, function (array $a, array $b): int {
            $tA = CarbonImmutable::parse((string) ($a['createdAt'] ?? ''))->getTimestamp();
            $tB = CarbonImmutable::parse((string) ($b['createdAt'] ?? ''))->getTimestamp();

            return $tB <=> $tA;
        });

        return $matchedBets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawBets
     * @return array{
     *     bets: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>
     * }
     */
    public function calculateDatePnL(array $rawBets, string $timezone): array
    {
        $totalStaked = 0.0;
        $settledStaked = 0.0;
        $activeStaked = 0.0;
        $totalPayout = 0.0;
        $wonCount = 0;
        $lostCount = 0;
        $cashoutCount = 0;
        $voidCount = 0;
        $activeCount = 0;

        $standardizedBets = [];

        foreach ($rawBets as $bet) {
            $amount = (float) ($bet['amount'] ?? 0);
            $payout = (float) ($bet['payout'] ?? 0);
            $currency = mb_strtoupper((string) ($bet['currency'] ?? 'USDT'));
            $potentialMultiplier = (float) ($bet['potentialMultiplier'] ?? 1);
            $rawStatus = mb_strtolower((string) ($bet['status'] ?? 'pending'));
            $isActive = (bool) ($bet['active'] ?? false);

            $createdAtStr = (string) ($bet['createdAt'] ?? '');
            $created = $createdAtStr !== ''
                ? CarbonImmutable::parse($createdAtStr)->setTimezone($timezone)
                : CarbonImmutable::now($timezone);

            if ($isActive || $rawStatus === 'confirmed') {
                $status = 'pending';
                $statusLabel = '進行中';
                $profit = 0.0;
                $activeStaked += $amount;
                $activeCount++;
            } elseif ($rawStatus === 'cashout') {
                $status = 'cashout';
                $statusLabel = '已兌現';
                $profit = $payout - $amount;
                $settledStaked += $amount;
                $totalPayout += $payout;
                $cashoutCount++;
            } elseif (in_array($rawStatus, ['cancelled', 'void', 'refund', 'refunded'], true) || (abs($payout - $amount) < 0.001 && $rawStatus === 'settled')) {
                $status = 'void';
                $statusLabel = '退款';
                $profit = 0.0;
                $settledStaked += $amount;
                $totalPayout += $payout;
                $voidCount++;
            } elseif ($rawStatus === 'settled' && $payout > 0) {
                $status = 'won';
                $statusLabel = '獲勝';
                $profit = $payout - $amount;
                $settledStaked += $amount;
                $totalPayout += $payout;
                $wonCount++;
            } else {
                $status = 'lost';
                $statusLabel = '未中獎';
                $profit = -$amount;
                $settledStaked += $amount;
                $totalPayout += 0.0;
                $lostCount++;
            }

            $totalStaked += $amount;

            $outcomes = is_array($bet['outcomes'] ?? null) ? $bet['outcomes'] : [];
            $legCount = count($outcomes);
            $isParlay = $legCount > 1;

            $legs = [];
            foreach ($outcomes as $outcome) {
                $marketOutcome = is_array($outcome['outcome'] ?? null) ? $outcome['outcome'] : [];
                $market = is_array($outcome['market'] ?? null) ? $outcome['market'] : [];
                $fixture = is_array($outcome['fixture'] ?? null) ? $outcome['fixture'] : [];
                $tournament = is_array($fixture['tournament'] ?? null) ? $fixture['tournament'] : [];
                $category = is_array($tournament['category'] ?? null) ? $tournament['category'] : [];
                $sport = is_array($category['sport'] ?? null) ? $category['sport'] : [];

                $sportName = (string) ($sport['name'] ?? '');
                $sportSlug = (string) ($sport['slug'] ?? '');
                $tournamentName = (string) ($tournament['name'] ?? '');
                $fixtureName = (string) ($fixture['name'] ?? '');
                $marketName = (string) ($market['name'] ?? '');
                $outcomeName = (string) ($marketOutcome['name'] ?? '');
                $odds = (float) ($outcome['odds'] ?? ($marketOutcome['odds'] ?? 1.0));
                $legRawStatus = mb_strtolower((string) ($outcome['status'] ?? 'pending'));

                $legStatus = match ($legRawStatus) {
                    'won' => 'won',
                    'lost' => 'lost',
                    'void', 'refund', 'refunded', 'cancelled' => 'void',
                    default => 'pending',
                };
                $legSymbol = match ($legStatus) {
                    'won' => '✔️',
                    'lost' => '❌',
                    'void' => '⚪',
                    default => '⏳',
                };

                $legs[] = [
                    'sport_name' => ChineseConverter::toTraditional($sportName),
                    'sport_slug' => $sportSlug,
                    'tournament_name' => ChineseConverter::toTraditional($tournamentName),
                    'fixture_name' => ChineseConverter::toTraditional($fixtureName),
                    'market_name' => ChineseConverter::toTraditional($marketName),
                    'outcome_name' => ChineseConverter::toTraditional($outcomeName),
                    'odds' => $odds,
                    'status' => $legStatus,
                    'status_symbol' => $legSymbol,
                ];
            }

            $standardizedBets[] = [
                'id' => (string) ($bet['id'] ?? ''),
                'iid' => (string) ($bet['bet']['iid'] ?? ''),
                'amount' => $amount,
                'payout' => $payout,
                'profit' => $profit,
                'currency' => $currency,
                'potential_multiplier' => $potentialMultiplier,
                'status' => $status,
                'status_label' => $statusLabel,
                'is_parlay' => $isParlay,
                'leg_count' => $legCount,
                'created_at' => $created,
                'created_at_formatted' => $created->format('m/d H:i'),
                'created_time_only' => $created->format('H:i'),
                'legs' => $legs,
            ];
        }

        $netProfit = $totalPayout - $settledStaked;
        $roi = $settledStaked > 0 ? (($netProfit / $settledStaked) * 100) : 0.0;
        $decidedCount = $wonCount + $lostCount;
        $winRate = $decidedCount > 0 ? (($wonCount / $decidedCount) * 100) : 0.0;

        $summary = [
            'total_staked' => $totalStaked,
            'settled_staked' => $settledStaked,
            'active_staked' => $activeStaked,
            'total_payout' => $totalPayout,
            'net_profit' => $netProfit,
            'roi' => $roi,
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'cashout_count' => $cashoutCount,
            'void_count' => $voidCount,
            'active_count' => $activeCount,
            'total_count' => count($standardizedBets),
            'win_rate' => $winRate,
        ];

        return [
            'bets' => $standardizedBets,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bets
     * @param  array<string, mixed>  $summary
     * @param  array{available: float, vault: float, total: float}|null  $balance
     */
    public function formatBetHistoryMessage(
        CarbonImmutable $startDate,
        ?CarbonImmutable $endDate,
        array $bets,
        array $summary,
        ?array $balance = null,
        string $timezone = 'Asia/Taipei'
    ): string {
        $endDate = $endDate ?? $startDate;
        $now = CarbonImmutable::now($timezone);
        $isRange = ! $startDate->isSameDay($endDate);

        if ($isRange) {
            $days = (int) $startDate->diffInDays($endDate) + 1;
            $title = 'Stake 體育投注｜區間損益與紀錄';
            $subtitle = sprintf('區間｜%s ~ %s（共 %d 天）・台灣時間', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $days);
        } else {
            $dateDesc = match (true) {
                $startDate->isSameDay($now) => '今天',
                $startDate->isSameDay($now->subDay()) => '昨天',
                default => $startDate->isoFormat('dddd'),
            };
            $title = 'Stake 體育投注｜每日損益與紀錄';
            $subtitle = sprintf('日期｜%s（%s）・台灣時間', $startDate->format('Y-m-d'), $dateDesc);
        }

        $lines = [
            $title,
            $subtitle,
            '',
            '【📊 損益總覽】',
            sprintf('・總投注額：%s USDT（%d 筆注單）', $this->formatNumber($summary['total_staked']), $summary['total_count']),
            sprintf('・總返還額：%s USDT', $this->formatNumber($summary['total_payout'])),
        ];

        $netProfit = $summary['net_profit'];
        $profitSign = $netProfit > 0.001 ? '+' : '';
        $profitTag = match (true) {
            $netProfit > 0.001 => '（盈）📈',
            $netProfit < -0.001 => '（虧）📉',
            default => '（平）⚖️',
        };
        $lines[] = sprintf('・淨損益：%s%s USDT%s', $profitSign, $this->formatNumber($netProfit), $profitTag);

        $roi = $summary['roi'];
        $roiSign = $roi > 0.001 ? '+' : '';
        $lines[] = sprintf('・投資報酬率（ROI）：%s%.1f%%', $roiSign, $roi);

        $recordParts = [
            sprintf('%d 勝', $summary['won_count']),
            sprintf('%d 負', $summary['lost_count']),
        ];
        if ($summary['cashout_count'] > 0) {
            $recordParts[] = sprintf('%d 兌現', $summary['cashout_count']);
        }
        if ($summary['void_count'] > 0) {
            $recordParts[] = sprintf('%d 退款', $summary['void_count']);
        }
        if ($summary['active_count'] > 0) {
            $recordParts[] = sprintf('%d 進行中', $summary['active_count']);
        }

        $decidedCount = $summary['won_count'] + $summary['lost_count'];
        $recordLine = implode(' ', $recordParts);
        if ($decidedCount > 0) {
            $recordLine .= sprintf('（勝率 %.1f%%）', $summary['win_rate']);
        }
        $lines[] = '・戰績：'.$recordLine;

        if ($balance !== null) {
            $lines[] = '・資金水位：'.$this->formatBalanceLine($balance);
        }

        if ($bets === []) {
            $lines[] = '';
            $lines[] = '──────────';
            $lines[] = $isRange ? '該區間查無投注紀錄。' : '該日期查無投注紀錄。';
            $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

            return implode("\n", $lines);
        }

        $displayBets = array_slice($bets, 0, self::MAX_DISPLAY_RECORD_BETS);
        foreach ($displayBets as $index => $bet) {
            $lines[] = "\n──────────";
            $typeLabel = $bet['is_parlay'] ? "{$bet['leg_count']} 關串關" : '單注';
            $iid = $bet['iid'] !== '' ? "｜#{$bet['iid']}" : '';
            $statusEmoji = match ($bet['status']) {
                'won' => '🏆',
                'lost' => '❌',
                'cashout' => '💰',
                'void' => '⚪',
                default => '⏳',
            };

            $timeStr = $isRange ? $bet['created_at_formatted'] : $bet['created_time_only'];
            $lines[] = sprintf(
                '【注單 %d】%s%s｜%s',
                $index + 1,
                $typeLabel,
                $iid,
                $timeStr
            );
            $lines[] = sprintf('・狀態：%s %s', $bet['status_label'], $statusEmoji);

            $multiplierStr = sprintf('%.3f', $bet['potential_multiplier']);
            $lines[] = sprintf(
                '・投注：%s %s @ %s',
                $this->formatNumber($bet['amount']),
                $bet['currency'],
                $multiplierStr
            );

            if ($bet['status'] === 'pending') {
                $lines[] = '・返還：待結算 ⏳';
            } else {
                $bProfit = $bet['profit'];
                $bProfitSign = $bProfit > 0.001 ? '+' : '';
                $lines[] = sprintf(
                    '・返還：%s %s（盈虧：%s%s %s）',
                    $this->formatNumber($bet['payout']),
                    $bet['currency'],
                    $bProfitSign,
                    $this->formatNumber($bProfit),
                    $bet['currency']
                );
            }

            $legs = $bet['legs'];
            if ($bet['is_parlay']) {
                $lines[] = '・賽事關卡：';
                foreach ($legs as $legIdx => $leg) {
                    $prefix = $leg['sport_name'] !== '' ? "【{$leg['sport_name']}】" : '';
                    $lines[] = sprintf(
                        '  %d. %s%s｜%s',
                        $legIdx + 1,
                        $prefix,
                        $leg['tournament_name'] ?: $leg['fixture_name'],
                        $leg['fixture_name']
                    );
                    $lines[] = sprintf(
                        '     選項：%s @ %.2f（%s）',
                        $leg['outcome_name'] ?: $leg['market_name'],
                        $leg['odds'],
                        $leg['status_symbol']
                    );
                }
            } else {
                $leg = $legs[0] ?? null;
                if ($leg !== null) {
                    $prefix = $leg['sport_name'] !== '' ? "【{$leg['sport_name']}】" : '';
                    $lines[] = sprintf(
                        '・賽事：%s%s',
                        $prefix,
                        $leg['tournament_name'] ?: $leg['fixture_name']
                    );
                    if ($leg['fixture_name'] !== '') {
                        $lines[] = "  {$leg['fixture_name']}";
                    }
                    $marketDesc = $leg['market_name'] !== '' ? "（{$leg['market_name']}）" : '';
                    $lines[] = sprintf(
                        '  選項：%s @ %.2f%s（%s）',
                        $leg['outcome_name'],
                        $leg['odds'],
                        $marketDesc,
                        $leg['status_symbol']
                    );
                }
            }
        }

        if (count($bets) > count($displayBets)) {
            $lines[] = sprintf("\n另有 %d 筆注單，請至 Stake 查看。", count($bets) - count($displayBets));
        }

        $lines[] = '';
        $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bets
     * @param  array<string, mixed>  $summary
     * @param  array{available: float, vault: float, total: float}|null  $balance
     * @return array<string, mixed>
     */
    public function buildBetHistoryImageData(
        CarbonImmutable $startDate,
        ?CarbonImmutable $endDate,
        array $bets,
        array $summary,
        ?array $balance = null,
        string $timezone = 'Asia/Taipei'
    ): array {
        $endDate = $endDate ?? $startDate;
        $now = CarbonImmutable::now($timezone);
        $isRange = ! $startDate->isSameDay($endDate);

        if ($isRange) {
            $days = (int) $startDate->diffInDays($endDate) + 1;
            $title = 'Stake 體育投注｜區間損益與紀錄';
            $subtitle = sprintf('區間｜%s ~ %s（共 %d 天）・台灣時間', $startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $days);
            $dateFormatted = sprintf('%s ~ %s', $startDate->format('m/d'), $endDate->format('m/d'));
            $dateDesc = sprintf('%d 天', $days);
        } else {
            $dateDesc = match (true) {
                $startDate->isSameDay($now) => '今天',
                $startDate->isSameDay($now->subDay()) => '昨天',
                default => $startDate->isoFormat('dddd'),
            };
            $title = 'Stake 體育投注｜每日損益與紀錄';
            $subtitle = sprintf('日期｜%s（%s）・台灣時間', $startDate->format('Y-m-d'), $dateDesc);
            $dateFormatted = $startDate->format('Y-m-d');
        }

        $displayBets = array_slice($bets, 0, self::MAX_DISPLAY_RECORD_BETS);
        $formattedBets = [];
        foreach ($displayBets as $bet) {
            $formattedBets[] = [
                'id' => $bet['id'],
                'iid' => $bet['iid'],
                'status' => $bet['status'],
                'status_label' => $bet['status_label'],
                'is_parlay' => $bet['is_parlay'],
                'leg_count' => $bet['leg_count'],
                'created_at_formatted' => $bet['created_at_formatted'],
                'created_time_only' => $isRange ? $bet['created_at_formatted'] : $bet['created_time_only'],
                'amount_formatted' => $this->formatNumber($bet['amount']).' '.$bet['currency'],
                'odds_formatted' => sprintf('%.3f', $bet['potential_multiplier']),
                'payout_formatted' => $bet['status'] === 'pending'
                    ? '待結算'
                    : $this->formatNumber($bet['payout']).' '.$bet['currency'],
                'profit_formatted' => $bet['status'] === 'pending'
                    ? '浮動中'
                    : ($bet['profit'] >= 0 ? '+' : '').$this->formatNumber($bet['profit']).' '.$bet['currency'],
                'profit_val' => $bet['profit'],
                'legs' => $bet['legs'],
            ];
        }

        return [
            'type' => 'bet_history',
            'title' => $title,
            'subtitle' => $subtitle,
            'is_range' => $isRange,
            'date_formatted' => $dateFormatted,
            'date_desc' => $dateDesc,
            'summary' => [
                'total_staked' => $this->formatNumber($summary['total_staked']).' USDT',
                'total_payout' => $this->formatNumber($summary['total_payout']).' USDT',
                'net_profit' => ($summary['net_profit'] >= 0 ? '+' : '').$this->formatNumber($summary['net_profit']).' USDT',
                'net_profit_val' => $summary['net_profit'],
                'roi' => ($summary['roi'] >= 0 ? '+' : '').sprintf('%.1f%%', $summary['roi']),
                'roi_val' => $summary['roi'],
                'win_rate' => sprintf('%.1f%%', $summary['win_rate']),
                'win_rate_val' => $summary['win_rate'],
                'won_count' => $summary['won_count'],
                'lost_count' => $summary['lost_count'],
                'cashout_count' => $summary['cashout_count'],
                'void_count' => $summary['void_count'],
                'active_count' => $summary['active_count'],
                'total_count' => $summary['total_count'],
                'record_text' => sprintf(
                    '%d勝 %d負%s%s',
                    $summary['won_count'],
                    $summary['lost_count'],
                    $summary['cashout_count'] > 0 ? " {$summary['cashout_count']}兌現" : '',
                    $summary['active_count'] > 0 ? " {$summary['active_count']}進行中" : ''
                ),
            ],
            'bets' => $formattedBets,
            'balance_formatted' => $balance ? $this->formatBalanceLine($balance) : null,
            'omitted_count' => max(0, count($bets) - count($displayBets)),
        ];
    }

    /**
     * @param  array<int, mixed>  $balances
     * @return array{available: float, vault: float, total: float}|null
     */
    public function extractUsdtBalance(array $balances): ?array
    {
        foreach ($balances as $balance) {
            if (! is_array($balance)) {
                continue;
            }

            $availableCurrency = mb_strtolower((string) ($balance['available']['currency'] ?? ''));
            $vaultCurrency = mb_strtolower((string) ($balance['vault']['currency'] ?? ''));

            if ($availableCurrency === 'usdt' || $vaultCurrency === 'usdt') {
                $available = (float) ($balance['available']['amount'] ?? 0);
                $vault = (float) ($balance['vault']['amount'] ?? 0);

                return [
                    'available' => $available,
                    'vault' => $vault,
                    'total' => $available + $vault,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{available: float, vault: float, total: float}|null
     */
    public function getUsdtBalance(): ?array
    {
        $cacheSeconds = (int) config('services.stake.cache_seconds', 0);
        $cacheKey = 'stake:usdt_balance';

        if ($cacheSeconds > 0) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        $response = $this->sendGraphQLRequest('UserBalances', self::USER_BALANCES_QUERY, []);

        $balances = $response['data']['user']['balances'] ?? [];
        if (! is_array($balances)) {
            return null;
        }

        $usdtBalance = $this->extractUsdtBalance($balances);

        if ($usdtBalance !== null && $cacheSeconds > 0) {
            try {
                Cache::put($cacheKey, $usdtBalance, $cacheSeconds);
            } catch (Throwable) {
                // Cache error non-blocking
            }
        }

        return $usdtBalance;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function sendGraphQLRequest(string $operationName, string $query, array $variables): array
    {
        $accessToken = trim((string) config('services.stake.access_token'));
        $apiUrl = (string) config('services.stake.api_url', 'https://stake.com/_api/graphql');
        $timeout = (int) config('services.stake.timeout_seconds', 10);
        $language = (string) config('services.stake.language', 'zh');

        $headers = [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
            'x-access-token' => $accessToken,
            'x-language' => $language,
            'x-operation-name' => $operationName,
            'x-operation-type' => 'query',
        ];

        if ($cd = config('services.stake.kpsdk_cd')) {
            $headers['x-kpsdk-cd'] = (string) $cd;
        }
        if ($ct = config('services.stake.kpsdk_ct')) {
            $headers['x-kpsdk-ct'] = (string) $ct;
        }
        if ($h = config('services.stake.kpsdk_h')) {
            $headers['x-kpsdk-h'] = (string) $h;
        }
        if ($v = config('services.stake.kpsdk_v')) {
            $headers['x-kpsdk-v'] = (string) $v;
        }
        if ($cookie = config('services.stake.cookie')) {
            $headers['Cookie'] = (string) $cookie;
        }

        $userAgent = (string) (config('services.stake.user_agent') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $request = Http::acceptJson()
            ->withHeaders($headers)
            ->withUserAgent($userAgent)
            ->timeout($timeout);

        if ($proxy = config('services.stake.proxy')) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        $payload = [
            'query' => $query,
            'variables' => $variables === [] ? (object) [] : $variables,
        ];

        $response = $request->post($apiUrl, $payload);

        if (! $response->successful()) {
            $response->throw();
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RequestException($response);
        }

        return $json;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bets
     * @param  array{available: float, vault: float, total: float}|null  $balance
     */
    public function formatBetsMessage(array $bets, int $totalCount, ?array $balance = null): string
    {
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $lines = [
            "Stake 體育投注｜進行中注單（{$totalCount} 筆）",
            '時間基準｜台灣時間',
        ];

        $totalAmounts = [];

        foreach ($bets as $index => $bet) {
            $lines[] = "\n──────────";

            $outcomes = is_array($bet['outcomes'] ?? null) ? $bet['outcomes'] : [];
            $outcomeCount = count($outcomes);
            $isParlay = $outcomeCount > 1;
            $typeLabel = $isParlay ? "{$outcomeCount} 關串關" : '單注';

            $iid = $bet['bet']['iid'] ?? $bet['id'] ?? null;
            $idStr = '';
            if (is_string($iid) && $iid !== '') {
                $formattedIid = preg_replace('/^sport:/', '', $iid);
                $idStr = "｜#{$formattedIid}";
            }

            $lines[] = sprintf('【注單 %d】%s%s', $index + 1, $typeLabel, $idStr);

            $amount = (float) ($bet['amount'] ?? 0);
            $currency = mb_strtoupper((string) ($bet['currency'] ?? 'USDT'));
            $multiplier = (float) ($bet['potentialMultiplier'] ?? 1);
            $payout = $amount * $multiplier;

            $totalAmounts[$currency] = ($totalAmounts[$currency] ?? 0.0) + $amount;

            $lines[] = sprintf('・投注：%s %s', $this->formatNumber($amount), $currency);

            $lines[] = sprintf(
                '・總賠率：%.3f｜預估返還：%s %s',
                $multiplier,
                $this->formatNumber($payout),
                $currency
            );

            $cashoutState = $this->resolveCashoutState($bet);
            if ($cashoutState['disabled']) {
                $lines[] = '・即時兌現：暫停兌現 ⏸️';
            } elseif ($cashoutState['available'] && $cashoutState['formatted'] !== null) {
                $lines[] = '・即時兌現：'.$cashoutState['formatted'];
            }

            if (! empty($bet['createdAt'])) {
                try {
                    $createdAt = CarbonImmutable::parse($bet['createdAt'])->setTimezone($timezone);
                    $lines[] = '・下注時間：'.$createdAt->format('m/d H:i');
                } catch (Throwable) {
                    // Ignore parsing error
                }
            }

            $detailHeader = $isParlay ? '・關卡明細：' : '・賽事明細：';
            $lines[] = $detailHeader;

            foreach ($outcomes as $legIndex => $outcome) {
                if ($legIndex > 0) {
                    $lines[] = '';
                }

                $fixture = is_array($outcome['fixture'] ?? null) ? $outcome['fixture'] : [];

                $sportName = $fixture['tournament']['category']['sport']['name']
                    ?? $fixture['tournament']['category']['sport']['slug']
                    ?? null;
                $tournamentName = (string) ($fixture['tournament']['name'] ?? '');

                $sportNameTrad = $sportName !== null ? ChineseConverter::toTraditional($sportName) : '';
                $tournamentNameTrad = ChineseConverter::toTraditional($tournamentName);

                $sportPrefix = $sportNameTrad !== '' ? "【{$sportNameTrad}】" : '';
                $leagueLine = trim("{$sportPrefix}{$tournamentNameTrad}");

                $competitors = $fixture['data']['competitors'] ?? [];
                if (is_array($competitors) && count($competitors) >= 2) {
                    $c1 = $competitors[0]['name'] ?? ($competitors[0]['defaultName'] ?? '');
                    $c2 = $competitors[1]['name'] ?? ($competitors[1]['defaultName'] ?? '');
                    $matchName = trim("{$c1} vs {$c2}");
                } else {
                    $matchName = (string) ($fixture['name'] ?? '未知對戰');
                }
                $matchNameTrad = ChineseConverter::toTraditional($matchName);

                $matchLive = ChineseConverter::toTraditional($this->formatFixtureStatus($fixture, $timezone));

                $selectionName = ChineseConverter::toTraditional((string) ($outcome['outcome']['name'] ?? ''));
                $odds = (float) ($outcome['odds'] ?? $outcome['outcome']['odds'] ?? 0);
                $marketName = ChineseConverter::toTraditional((string) ($outcome['market']['name'] ?? ''));
                $marketSuffix = $marketName !== '' ? "（{$marketName}）" : '';

                $statusLabel = match (mb_strtolower((string) ($outcome['status'] ?? 'pending'))) {
                    'won' => '已過 ✅',
                    'lost' => '未過 ❌',
                    'pending' => '進行中 ⏳',
                    'settled' => '已結算',
                    'void', 'refund', 'refunded', 'cancelled' => '退本金 ⚪',
                    default => ChineseConverter::toTraditional((string) ($outcome['status'] ?? '進行中')),
                };

                if ($isParlay) {
                    $lines[] = sprintf('  關卡 %d/%d｜%s', $legIndex + 1, $outcomeCount, $statusLabel);
                } else {
                    $lines[] = sprintf('  狀態：%s', $statusLabel);
                }

                if ($leagueLine !== '') {
                    $lines[] = "  {$leagueLine}";
                }
                $lines[] = "  {$matchNameTrad}";
                $lines[] = "  賽況：{$matchLive}";
                $lines[] = sprintf('  選項：%s @ %.2f%s', $selectionName, $odds, $marketSuffix);
            }
        }

        $lines[] = "\n──────────";

        $summaryParts = [];
        foreach ($totalAmounts as $curr => $sum) {
            $summaryParts[] = sprintf('%s %s', $this->formatNumber($sum), $curr);
        }
        $totalStakedStr = implode(' / ', $summaryParts);

        $lines[] = sprintf('總計 %d 筆注單｜總投注：%s', $totalCount, $totalStakedStr);
        if ($balance !== null) {
            $lines[] = '資金水位｜'.$this->formatBalanceLine($balance);
        }
        $lines[] = '完整注單｜https://stake.com/zh/my-bets/sports';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bets
     * @param  array{available: float, vault: float, total: float}|null  $balance
     * @return array<string, mixed>
     */
    public function buildImageData(array $bets, int $totalCount, ?array $balance = null): array
    {
        $timezone = (string) config('services.bo3.timezone', 'Asia/Taipei');
        $totalAmounts = [];
        $betsForImage = [];

        foreach ($bets as $bet) {
            $outcomes = is_array($bet['outcomes'] ?? null) ? $bet['outcomes'] : [];
            $outcomeCount = count($outcomes);
            $isParlay = $outcomeCount > 1;
            $typeLabel = $isParlay ? "{$outcomeCount} 關串關" : '單注';

            $iid = $bet['bet']['iid'] ?? $bet['id'] ?? null;
            $idStr = '';
            if (is_string($iid) && $iid !== '') {
                $formattedIid = preg_replace('/^sport:/', '', $iid);
                $idStr = "#{$formattedIid}";
            }

            $amount = (float) ($bet['amount'] ?? 0);
            $currency = mb_strtoupper((string) ($bet['currency'] ?? 'USDT'));
            $multiplier = (float) ($bet['potentialMultiplier'] ?? 1);
            $payout = $amount * $multiplier;

            $totalAmounts[$currency] = ($totalAmounts[$currency] ?? 0.0) + $amount;

            $cashoutState = $this->resolveCashoutState($bet);
            $cashoutFormatted = ($cashoutState['available'] && ! $cashoutState['disabled'])
                ? $cashoutState['formatted']
                : null;
            $cashoutDisabled = $cashoutState['disabled'];
            $cashoutMultiplier = $cashoutState['multiplier'];

            $createdAtFormatted = null;
            if (! empty($bet['createdAt'])) {
                try {
                    $createdAtFormatted = CarbonImmutable::parse($bet['createdAt'])
                        ->setTimezone($timezone)
                        ->format('m/d H:i');
                } catch (Throwable) {
                    // Ignore
                }
            }

            $legsForImage = [];
            foreach ($outcomes as $legIndex => $outcome) {
                $fixture = is_array($outcome['fixture'] ?? null) ? $outcome['fixture'] : [];

                $sportRaw = $fixture['tournament']['category']['sport']['name']
                    ?? $fixture['tournament']['category']['sport']['slug']
                    ?? null;
                $sportSlug = (string) ($fixture['tournament']['category']['sport']['slug'] ?? '');
                $gameSlug = $this->resolveGameSlug($sportSlug, (string) $sportRaw);

                $sportNameTrad = $sportRaw !== null ? ChineseConverter::toTraditional((string) $sportRaw) : '';
                $tournamentNameTrad = ChineseConverter::toTraditional((string) ($fixture['tournament']['name'] ?? ''));

                $competitors = $fixture['data']['competitors'] ?? [];
                if (is_array($competitors) && count($competitors) >= 2) {
                    $c1 = $competitors[0]['name'] ?? ($competitors[0]['defaultName'] ?? '');
                    $c2 = $competitors[1]['name'] ?? ($competitors[1]['defaultName'] ?? '');
                    $matchName = trim("{$c1} vs {$c2}");
                } else {
                    $matchName = (string) ($fixture['name'] ?? '未知對戰');
                }
                $matchNameTrad = ChineseConverter::toTraditional($matchName);

                $matchLive = ChineseConverter::toTraditional($this->formatFixtureStatus($fixture, $timezone));
                $isLive = mb_strtolower((string) ($fixture['status'] ?? '')) === 'live';

                $selectionName = ChineseConverter::toTraditional((string) ($outcome['outcome']['name'] ?? ''));
                $odds = (float) ($outcome['odds'] ?? $outcome['outcome']['odds'] ?? 0);
                $marketName = ChineseConverter::toTraditional((string) ($outcome['market']['name'] ?? ''));

                $rawStatus = mb_strtolower((string) ($outcome['status'] ?? 'pending'));
                $statusLabel = match ($rawStatus) {
                    'won' => '已過 ✅',
                    'lost' => '未過 ❌',
                    'pending' => '進行中 ⏳',
                    'settled' => '已結算',
                    'void', 'refund', 'refunded', 'cancelled' => '退本金 ⚪',
                    default => ChineseConverter::toTraditional((string) ($outcome['status'] ?? '進行中')),
                };

                $legsForImage[] = [
                    'leg_index' => $legIndex + 1,
                    'total_legs' => $outcomeCount,
                    'status' => $rawStatus,
                    'status_label' => $statusLabel,
                    'game' => $gameSlug,
                    'sport_name' => $sportNameTrad,
                    'tournament' => $tournamentNameTrad,
                    'match_name' => $matchNameTrad,
                    'match_status' => $matchLive,
                    'is_live' => $isLive,
                    'selection' => $selectionName,
                    'odds' => sprintf('%.2f', $odds),
                    'market' => $marketName,
                ];
            }

            $betsForImage[] = [
                'type_label' => $typeLabel,
                'is_parlay' => $isParlay,
                'iid' => $idStr,
                'amount_formatted' => $this->formatNumber($amount).' '.$currency,
                'multiplier_formatted' => sprintf('%.3f', $multiplier),
                'payout_formatted' => $this->formatNumber($payout).' '.$currency,
                'cashout_formatted' => $cashoutFormatted,
                'cashout_disabled' => $cashoutDisabled,
                'cashout_multiplier' => $cashoutMultiplier,
                'created_at_formatted' => $createdAtFormatted,
                'legs' => $legsForImage,
            ];
        }

        $summaryParts = [];
        foreach ($totalAmounts as $curr => $sum) {
            $summaryParts[] = sprintf('%s %s', $this->formatNumber($sum), $curr);
        }
        $totalStakedStr = implode(' / ', $summaryParts);

        return [
            'type' => 'bets',
            'title' => 'Stake 體育投注',
            'subtitle' => sprintf('台灣時間｜共 %d 筆進行中注單', $totalCount),
            'total_count' => $totalCount,
            'total_staked' => $totalStakedStr,
            'balance_formatted' => $balance !== null ? $this->formatBalanceForImage($balance) : null,
            'bets' => $betsForImage,
        ];
    }

    private function resolveGameSlug(string $slug, string $name): ?string
    {
        $normalized = mb_strtolower($slug.' '.$name);

        if (str_contains($normalized, 'league-of-legends') || str_contains($normalized, '英雄聯盟') || str_contains($normalized, '英雄联盟') || str_contains($normalized, 'lol')) {
            return 'lol';
        }

        if (str_contains($normalized, 'valorant') || str_contains($normalized, '無畏契約') || str_contains($normalized, '无畏契约')) {
            return 'valorant';
        }

        if (str_contains($normalized, 'counter-strike') || str_contains($normalized, 'cs2') || str_contains($normalized, 'cs:go') || str_contains($normalized, 'cs')) {
            return 'cs';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function formatFixtureStatus(array $fixture, string $timezone): string
    {
        $status = mb_strtolower((string) ($fixture['status'] ?? ''));
        $eventStatus = is_array($fixture['eventStatus'] ?? null) ? $fixture['eventStatus'] : [];
        $matchStatus = isset($eventStatus['matchStatus']) && trim((string) $eventStatus['matchStatus']) !== ''
            ? trim((string) $eventStatus['matchStatus'])
            : null;

        $homeScore = isset($eventStatus['homeScore']) && is_numeric($eventStatus['homeScore'])
            ? (int) $eventStatus['homeScore']
            : null;
        $awayScore = isset($eventStatus['awayScore']) && is_numeric($eventStatus['awayScore'])
            ? (int) $eventStatus['awayScore']
            : null;

        $periodScore = $this->resolveCurrentPeriodScore($eventStatus, $matchStatus);
        $stageText = $matchStatus;
        if ($periodScore !== null) {
            $stageText = $stageText !== null ? "{$stageText} {$periodScore}" : $periodScore;
        }

        $startTime = $fixture['data']['startTime'] ?? null;

        if ($status === 'live') {
            if ($homeScore !== null && $awayScore !== null) {
                $scoreText = "{$homeScore}-{$awayScore}";

                return $stageText !== null
                    ? "滾球中（{$scoreText}，{$stageText}）"
                    : "滾球中（{$scoreText}）";
            }

            return $stageText !== null ? "滾球中（{$stageText}）" : '滾球進行中';
        }

        if (is_string($startTime) && trim($startTime) !== '') {
            try {
                $startAt = CarbonImmutable::parse($startTime)->setTimezone($timezone);
                $timeText = $startAt->isToday()
                    ? $startAt->format('H:i')
                    : $startAt->format('m/d H:i');

                return ($matchStatus !== null && ! in_array($matchStatus, ['未开始', '未開始'], true))
                    ? "{$timeText} 開賽（{$matchStatus}）"
                    : "{$timeText} 開賽";
            } catch (Throwable) {
                // Fallthrough to matchStatus
            }
        }

        return $matchStatus ?? '未開賽';
    }

    /**
     * @param  array<string, mixed>  $eventStatus
     */
    private function resolveCurrentPeriodScore(array $eventStatus, ?string $matchStatus): ?string
    {
        $scoreboard = is_array($eventStatus['scoreboard'] ?? null) ? $eventStatus['scoreboard'] : null;

        if ($scoreboard !== null) {
            // Rounds (CS2 / Valorant)
            if (isset($scoreboard['homeWonRounds'], $scoreboard['awayWonRounds'])
                && is_numeric($scoreboard['homeWonRounds'])
                && is_numeric($scoreboard['awayWonRounds'])) {
                return ((int) $scoreboard['homeWonRounds']).'-'.((int) $scoreboard['awayWonRounds']);
            }

            // Kills (LoL / Dota 2)
            if (isset($scoreboard['homeKills'], $scoreboard['awayKills'])
                && is_numeric($scoreboard['homeKills'])
                && is_numeric($scoreboard['awayKills'])) {
                return ((int) $scoreboard['homeKills']).'-'.((int) $scoreboard['awayKills']);
            }

            // Goals (Soccer / Rocket League)
            if (isset($scoreboard['homeGoals'], $scoreboard['awayGoals'])
                && is_numeric($scoreboard['homeGoals'])
                && is_numeric($scoreboard['awayGoals'])) {
                return ((int) $scoreboard['homeGoals']).'-'.((int) $scoreboard['awayGoals']);
            }
        }

        // Tennis game score
        if (isset($eventStatus['homeGameScore'], $eventStatus['awayGameScore'])
            && trim((string) $eventStatus['homeGameScore']) !== ''
            && trim((string) $eventStatus['awayGameScore']) !== '') {
            return trim((string) $eventStatus['homeGameScore']).'-'.trim((string) $eventStatus['awayGameScore']);
        }

        // Period scores fallback
        $periodScores = is_array($eventStatus['periodScores'] ?? null) ? $eventStatus['periodScores'] : [];
        foreach ($periodScores as $p) {
            if (! is_array($p)) {
                continue;
            }

            if (($p['type'] ?? null) === 'map') {
                if ($matchStatus !== null && ($p['matchStatus'] ?? null) === $matchStatus) {
                    if (isset($p['homeWonRounds'], $p['awayWonRounds']) && is_numeric($p['homeWonRounds']) && is_numeric($p['awayWonRounds'])) {
                        return ((int) $p['homeWonRounds']).'-'.((int) $p['awayWonRounds']);
                    }
                    if (isset($p['homeKills'], $p['awayKills']) && is_numeric($p['homeKills']) && is_numeric($p['awayKills'])) {
                        return ((int) $p['homeKills']).'-'.((int) $p['awayKills']);
                    }
                }
            } else {
                if ($matchStatus !== null && ($p['matchStatus'] ?? null) === $matchStatus) {
                    if (isset($p['homeScore'], $p['awayScore']) && is_numeric($p['homeScore']) && is_numeric($p['awayScore'])) {
                        return ((int) $p['homeScore']).'-'.((int) $p['awayScore']);
                    }
                }
            }
        }

        return null;
    }

    private function formatNumber(float $number): string
    {
        $formatted = number_format($number, 2, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function formatBalanceAmount(float $amount): string
    {
        if ($amount > 0 && $amount < 0.01) {
            $formatted = number_format($amount, 4, '.', ',');

            return rtrim(rtrim($formatted, '0'), '.');
        }

        return $this->formatNumber($amount);
    }

    /**
     * @param  array{available: float, vault: float, total: float}  $balance
     */
    public function formatBalanceLine(array $balance): string
    {
        $availStr = $this->formatBalanceAmount($balance['available']);

        if ($balance['vault'] >= 0.0001) {
            $vaultStr = $this->formatBalanceAmount($balance['vault']);
            $totalStr = $this->formatBalanceAmount($balance['total']);

            return sprintf('可用：%s USDT（金庫：%s USDT / 總計：%s USDT）', $availStr, $vaultStr, $totalStr);
        }

        return sprintf('可用：%s USDT', $availStr);
    }

    /**
     * @param  array{available: float, vault: float, total: float}  $balance
     */
    public function formatBalanceForImage(array $balance): string
    {
        $availStr = $this->formatBalanceAmount($balance['available']);

        if ($balance['vault'] >= 0.0001) {
            $vaultStr = $this->formatBalanceAmount($balance['vault']);

            return sprintf('%s USDT（金庫 %s）', $availStr, $vaultStr);
        }

        return sprintf('%s USDT', $availStr);
    }

    /**
     * @param  array<string, mixed>  $bet
     * @return array{
     *     available: bool,
     *     disabled: bool,
     *     multiplier: float,
     *     amount: float,
     *     formatted: ?string
     * }
     */
    public function resolveCashoutState(array $bet): array
    {
        $amount = (float) ($bet['amount'] ?? 0);
        $currency = mb_strtoupper((string) ($bet['currency'] ?? 'USDT'));
        $potentialMultiplier = (float) ($bet['potentialMultiplier'] ?? 1);
        $payout = $amount * $potentialMultiplier;

        $rawCashoutMultiplier = (float) ($bet['cashoutMultiplier'] ?? 0);
        $rawCashoutDisabled = (bool) ($bet['cashoutDisabled'] ?? false);

        $outcomes = is_array($bet['outcomes'] ?? null) ? $bet['outcomes'] : [];

        if ($rawCashoutDisabled) {
            return [
                'available' => false,
                'disabled' => true,
                'multiplier' => 0.0,
                'amount' => 0.0,
                'formatted' => null,
            ];
        }

        $isAnyLegLive = false;
        $hasSuspendedLeg = false;
        $hasLiveProbabilities = false;
        $hasOddsMoved = false;
        $combinedProb = 1.0;
        $pendingLegCount = 0;
        $placedOddsProduct = 1.0;
        $liveOddsProduct = 1.0;

        foreach ($outcomes as $outcome) {
            $rawStatus = mb_strtolower((string) ($outcome['status'] ?? 'pending'));

            if ($rawStatus === 'lost') {
                return [
                    'available' => false,
                    'disabled' => false,
                    'multiplier' => 0.0,
                    'amount' => 0.0,
                    'formatted' => null,
                ];
            }

            if (in_array($rawStatus, ['won', 'void', 'refund', 'refunded', 'cancelled'], true)) {
                continue;
            }

            $pendingLegCount++;

            $fixture = is_array($outcome['fixture'] ?? null) ? $outcome['fixture'] : [];
            $fixtureStatus = mb_strtolower((string) ($fixture['status'] ?? ''));
            if ($fixtureStatus === 'live') {
                $isAnyLegLive = true;
            }

            $market = is_array($outcome['market'] ?? null) ? $outcome['market'] : [];
            $marketStatus = mb_strtolower((string) ($market['status'] ?? 'active'));

            $marketOutcome = is_array($outcome['outcome'] ?? null) ? $outcome['outcome'] : [];
            $isOutcomeActive = $marketOutcome['active'] ?? true;

            $fixtureCashout = $fixture['cashoutEnabled'] ?? true;
            $tournamentCashout = $fixture['tournament']['cashoutEnabled'] ?? true;
            $categoryCashout = $fixture['tournament']['category']['cashoutEnabled'] ?? true;
            $sportCashout = $fixture['tournament']['category']['sport']['cashoutConfiguration']['cashoutEnabled'] ?? true;

            $liveOdds = isset($marketOutcome['odds']) ? (float) $marketOutcome['odds'] : null;
            $liveProb = isset($marketOutcome['probabilities']) && is_numeric($marketOutcome['probabilities'])
                ? (float) $marketOutcome['probabilities']
                : null;

            $placedOdds = (float) ($outcome['odds'] ?? 0);
            if ($liveOdds !== null && $liveOdds > 0 && abs($liveOdds - $placedOdds) > 0.001) {
                $hasOddsMoved = true;
            }

            if ($liveProb !== null && $liveProb > 0) {
                $hasLiveProbabilities = true;
            }

            if (
                $marketStatus === 'suspended'
                || ! $isOutcomeActive
                || ! $fixtureCashout
                || ! $tournamentCashout
                || ! $categoryCashout
                || ! $sportCashout
                || ($liveOdds !== null && $liveOdds <= 0 && ($liveProb === null || $liveProb <= 0))
            ) {
                $hasSuspendedLeg = true;
            }

            if ($placedOdds > 0) {
                $placedOddsProduct *= $placedOdds;
            }

            if ($liveOdds !== null && $liveOdds > 0) {
                $liveOddsProduct *= $liveOdds;
            } elseif ($placedOdds > 0) {
                $liveOddsProduct *= $placedOdds;
            }

            if ($liveProb !== null && $liveProb > 0) {
                $legProb = $liveProb;
            } elseif ($liveOdds !== null && $liveOdds > 0) {
                $legProb = 1.0 / $liveOdds;
            } else {
                $legProb = $placedOdds > 0 ? 1.0 / $placedOdds : 1.0;
            }

            $combinedProb *= max(0.0001, min(1.0, $legProb));
        }

        if ($hasSuspendedLeg) {
            return [
                'available' => false,
                'disabled' => true,
                'multiplier' => 0.0,
                'amount' => 0.0,
                'formatted' => null,
            ];
        }

        if ($amount <= 0 || $pendingLegCount === 0) {
            return [
                'available' => false,
                'disabled' => false,
                'multiplier' => 0.0,
                'amount' => 0.0,
                'formatted' => null,
            ];
        }

        $isStaleDefault = abs($rawCashoutMultiplier - 0.99) < 0.001;
        if ($isAnyLegLive && ($hasLiveProbabilities || ($isStaleDefault && $hasOddsMoved) || $rawCashoutMultiplier <= 0)) {
            $ev = $payout * $combinedProb;
            $oddsRatio = ($placedOddsProduct > 0 && $liveOddsProduct > 0)
                ? ($placedOddsProduct / $liveOddsProduct)
                : 1.0;

            $factor = $oddsRatio < 1.0
                ? min(0.98, 0.9356 + (0.058 * (1.0 - $oddsRatio)))
                : 0.9356;

            $cashoutAmount = max(0.0, min($payout, $ev * $factor));
            $calcMultiplier = $amount > 0 ? ($cashoutAmount / $amount) : 0.0;

            return [
                'available' => true,
                'disabled' => false,
                'multiplier' => $calcMultiplier,
                'amount' => $cashoutAmount,
                'formatted' => sprintf(
                    '%s %s（%.3fx）',
                    $this->formatNumber($cashoutAmount),
                    $currency,
                    $calcMultiplier
                ),
            ];
        }

        if ($rawCashoutMultiplier > 0) {
            $cashoutAmount = $amount * $rawCashoutMultiplier;

            return [
                'available' => true,
                'disabled' => false,
                'multiplier' => $rawCashoutMultiplier,
                'amount' => $cashoutAmount,
                'formatted' => sprintf(
                    '%s %s（%.3fx）',
                    $this->formatNumber($cashoutAmount),
                    $currency,
                    $rawCashoutMultiplier
                ),
            ];
        }

        return [
            'available' => false,
            'disabled' => false,
            'multiplier' => 0.0,
            'amount' => 0.0,
            'formatted' => null,
        ];
    }
}
