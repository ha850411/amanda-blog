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

    public const FETCH_ACTIVE_SPORT_BETS_QUERY = <<<'GRAPHQL'
query FetchActiveSportBets($limit: Int!, $offset: Int!, $name: String) {
  user(name: $name) {
    id
    activeSportBets(limit: $limit, offset: $offset, sort: placedTime) {
      ...SportBetPreview_SportBet
    }
  }
}

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
  }
  createdAt
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

        $usdtBalance = null;
        foreach ($balances as $balance) {
            if (! is_array($balance)) {
                continue;
            }

            $availableCurrency = mb_strtolower((string) ($balance['available']['currency'] ?? ''));
            $vaultCurrency = mb_strtolower((string) ($balance['vault']['currency'] ?? ''));

            if ($availableCurrency === 'usdt' || $vaultCurrency === 'usdt') {
                $available = (float) ($balance['available']['amount'] ?? 0);
                $vault = (float) ($balance['vault']['amount'] ?? 0);
                $usdtBalance = [
                    'available' => $available,
                    'vault' => $vault,
                    'total' => $available + $vault,
                ];
                break;
            }
        }

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
