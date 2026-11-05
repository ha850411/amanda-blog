<?php

namespace App\Services;

use App\Services\LineSchedule\Support\StakeOddsCatalog;
use App\Support\ChineseConverter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StakeOddsService
{
    private const TIMEZONE = 'Asia/Taipei';

    private const PAGE_SIZE = 12;

    private const FIXTURE_FIELDS = <<<'GRAPHQL'
id name status slug
data { ... on SportFixtureDataMatch { startTime competitors { name abbreviation } } }
tournament { name slug category { slug sport { name slug } } }
GRAPHQL;

    private const MARKET_FIELDS = <<<'GRAPHQL'
groups(groups: ["winner", "handicap", "total", "Handicap", "Total"]) {
  name
  templates {
    markets { id name status specifiers outcomes { id name odds active } }
  }
}
GRAPHQL;

    public function __construct(private readonly StakeApiClient $api) {}

    public function reply(string $argument): LineBotReply
    {
        if ($argument === '' || mb_strtolower($argument) === 'help') {
            return new LineBotReply($this->help());
        }
        try {
            $command = $this->parse($argument);
        } catch (InvalidArgumentException $exception) {
            return new LineBotReply($exception->getMessage()."\n\n".$this->help());
        }
        if (mb_strtolower($command['team']) === 'teams') {
            return $this->teams($command['game']);
        }

        try {
            $isLive = false;
            $expectedGame = $command['game'];
            if ($command['code'] !== null) {
                $id = app(StakeFixtureCodes::class)->fixtureId($command['code']);
                if ($id === null) {
                    return new LineBotReply('遊戲編號不存在或已過期，請重新執行 !match，或用隊名／Stake 比賽網址查詢。');
                }
                $fixture = $this->fixture($id, null);
            } elseif ($command['slug'] !== null || $command['id'] !== null) {
                $fixture = $this->fixture($command['id'], $command['slug']);
            } else {
                $matches = $this->findFixtures($command['team'], $command['date'], $command['game']);
                if ($matches === []) {
                    $date = $command['date']?->format('Y/m/d').'（台灣時間）';
                    $label = $command['game'] === null ? 'LoL／CS2／VAL／MLB／NBA' : StakeOddsCatalog::GAMES[$command['game']]['label'];

                    return new LineBotReply('查無 '.$command['team'].' '.($command['date'] === null ? '進行中或尚未開打' : $date).' 的 '.$label.' 賽事。'
                        ."\n可用 !odds teams 查看隊名，或直接輸入 !odds 加上 Stake 比賽網址。\n只提供 Stake 目前開放的盤口，沒有歷史賠率。");
                }

                // A date can contain two series; a shared abbreviation can refer
                // to different squads. Let the user select a stable fixture ID.
                $identities = array_unique(array_map(fn (array $match): string => $match['_game'].':'.$match['_team'], $matches));
                $firstTime = $matches[0]['_start_at'];
                if (count($identities) > 1 || ($command['date'] !== null && count($matches) > 1)
                    || (count($matches) > 1 && $matches[1]['_start_at']->equalTo($firstTime)
                        && $matches[1]['_is_live'] === $matches[0]['_is_live'])) {
                    return $this->choices($matches, $command['text']);
                }
                $isLive = $matches[0]['_is_live'];
                $expectedGame = $matches[0]['_game'];
                $fixture = $this->fixture($matches[0]['id'], null);
            }

            if ($fixture === null) {
                return new LineBotReply('Stake 已找不到這場比賽，請使用最新的比賽網址查詢。');
            }
            $game = StakeOddsCatalog::fixtureGame($fixture);
            if ($game === null) {
                return new LineBotReply('這場比賽不在支援範圍；目前支援 LoL、CS2、VAL、MLB 與 NBA。');
            }
            if ($expectedGame !== null && $expectedGame !== $game) {
                return new LineBotReply('這場比賽屬於 '.StakeOddsCatalog::GAMES[$game]['label'].'，與查詢的 game='.$expectedGame.' 不符。');
            }
            $startAt = $this->startAt($fixture);
            if ($startAt === null) {
                throw new RuntimeException('Stake fixture is missing its start time.');
            }
            if ($command['date'] !== null && ! $startAt->isSameDay($command['date'])) {
                return new LineBotReply('這場比賽的台灣開賽時間為 '.$startAt->format('Y/m/d H:i').'，與指定日期不符。');
            }

            $url = $this->fixtureUrl($fixture);
            if (! in_array($fixture['status'] ?? '', ['active', 'live', 'suspended'], true)) {
                return new LineBotReply($fixture['name']."\n這場比賽已結束或未開放即時盤口。\n賽事連結｜".$url, $url);
            }

            $markets = $this->markets($fixture);
            $pages = max(1, (int) ceil(count($markets) / self::PAGE_SIZE));
            if ($command['page'] > $pages) {
                return new LineBotReply("目前只有 {$pages} 頁，請重新查詢第 1 頁。", $url, null, [
                    ['label' => '第 1 頁', 'text' => '!odds id='.$fixture['id'].($command['text'] ? ' text' : '')],
                ]);
            }
            $visible = array_slice($markets, ($command['page'] - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
            $checkedAt = CarbonImmutable::now(self::TIMEZONE)->format('Y/m/d H:i:s');
            $code = $this->code($fixture['id']);
            $lookup = $code ?? 'id='.$fixture['id'];
            $status = ($isLive || $fixture['status'] === 'live') ? '滾球中' : ($fixture['status'] === 'suspended' ? '暫停開盤' : '未開賽');
            $data = [
                'type' => 'odds',
                'title' => 'Stake｜'.StakeOddsCatalog::GAMES[$game]['label'].' 盤口',
                'game' => $game,
                'fixture_id' => $fixture['id'],
                'fixture_code' => $code,
                'fixture_name' => ChineseConverter::toTraditional($fixture['name']),
                'tournament' => ChineseConverter::toTraditional($fixture['tournament']['name'] ?? ''),
                'start_time' => $startAt->format('Y/m/d H:i'),
                'status' => $status,
                'checked_at' => $checkedAt,
                'markets' => $visible,
                'page' => $command['page'],
                'pages' => $pages,
            ];
            $lines = [$data['title'], $data['fixture_name'], $data['tournament'],
                '開賽｜'.$data['start_time'].'（台灣時間）｜'.$status,
                '查詢時間｜'.$checkedAt.'（台灣時間）'];
            if ($code !== null) {
                $lines[] = '遊戲編號｜'.$code.'（!odds '.$code.'）';
            }
            foreach ($visible as $market) {
                $lines[] = "\n【".$market['group_label'].'】'.$market['name'];
                foreach ($market['outcomes'] as $outcome) {
                    $lines[] = $outcome['name'].'｜'.$outcome['price'];
                }
            }
            if ($markets === []) {
                $lines[] = '獨贏／讓分／大小分：暫無盤口。';
            } else {
                foreach (['獨贏', '讓分', '大小分'] as $label) {
                    if (! in_array($label, array_column($markets, 'group_label'), true)) {
                        $lines[] = $label.'：暫無盤口。';
                    }
                }
            }
            $data['missing_groups'] = array_values(array_diff(['獨贏', '讓分', '大小分'], array_column($markets, 'group_label')));
            $lines[] = "\n第 {$command['page']} / {$pages} 頁｜賠率以 Stake 當下顯示為準";
            $lines[] = '賽事連結｜'.$url;
            $suffix = $command['text'] ? ' text' : '';
            $quick = [['label' => '更新盤口', 'text' => '!odds '.$lookup.' page='.$command['page'].$suffix]];
            foreach ([-1 => '上一頁', 1 => '下一頁'] as $delta => $label) {
                $page = $command['page'] + $delta;
                if ($page >= 1 && $page <= $pages) {
                    $quick[] = ['label' => $label, 'text' => '!odds '.$lookup.' page='.$page.$suffix];
                }
            }

            return new LineBotReply(implode("\n", $lines), $url, $command['text'] ? null : $data, $quick);
        } catch (Throwable $exception) {
            Log::warning('Stake odds query failed.', [
                'type' => $exception::class,
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);

            return new LineBotReply('目前無法取得完整的 Stake 賽事或盤口資料，請稍後再試。');
        }
    }

    private function help(): string
    {
        return "Stake 盤口｜LoL／CS2／VAL／MLB／NBA\n!odds 隊名 [日期] [game=項目] [text]\n!odds G2｜!odds G2 game=cs｜!odds G2 game=val\n!odds BLG 11/06｜!odds T1 明天 game=lol\n!odds 勇士 game=nba｜!odds 道奇 明天 game=mlb\n!odds K7M2Q（賽程或選單顯示的遊戲編號）\n!odds 加上 Stake 比賽網址（精準指定場次）\n!odds teams｜!odds teams game=nba｜!odds teams game=mlb\n\n省略 game= 時跨項目搜尋，跨項目同名會列出場次供選擇。\n電競：整場獨贏、地圖讓分、總局數；NBA／MLB：整場獨贏、讓分、總分。\n日期依台灣時間；省略年份時使用今年。\n未填日期：同隊同項目先找進行中，再找最近一場未開賽。\n指定日期有多場（含 MLB 雙重賽）會列出選項；只提供目前開放的 Stake 盤口。";
    }

    private function teams(?string $game): LineBotReply
    {
        if (in_array($game, ['nba', 'mlb'], true)) {
            $lines = [StakeOddsCatalog::GAMES[$game]['label'].' 隊伍｜支援中英文全名、簡稱與縮寫'];
            foreach (config('odds_teams.'.$game, []) as $english => $aliases) {
                $lines[] = $aliases[0].'｜'.$english;
            }
        } elseif (in_array($game, ['cs', 'val'], true)) {
            $lines = [StakeOddsCatalog::GAMES[$game]['label'].' 請使用隊伍全名或縮寫，例如 !odds G2 game='.$game,
                '可查詢 Stake 目前開放的賽事；同名隊伍會列出場次供選擇。'];
        } else {
            $lines = ['LoL 一級聯賽隊伍｜'.config('lol_teams.season')];
            foreach (config('lol_teams.leagues', []) as $league => $teams) {
                $lines[] = "\n【{$league}】";
                foreach ($teams as $team) {
                    $lines[] = $team['code'].'｜'.$team['name'].(isset($team['participation']) ? '（Versus 客席）' : '');
                }
            }
            $lines[] = "\n其他項目：!odds teams game=nba｜!odds teams game=mlb｜!odds teams game=cs｜!odds teams game=val";
        }

        return new LineBotReply(implode("\n", $lines));
    }

    private function parse(string $argument): array
    {
        $tokens = preg_split('/\s+/u', trim($argument));
        $parts = [];
        $result = ['team' => '', 'date' => null, 'game' => null, 'id' => null, 'slug' => null, 'code' => null, 'text' => false, 'page' => 1];
        foreach ($tokens as $token) {
            if (mb_strtolower($token) === 'text') {
                $result['text'] = true;
            } elseif (str_starts_with(mb_strtolower($token), 'game=')) {
                $game = StakeOddsCatalog::game(substr($token, 5));
                if ($game === null || ($result['game'] !== null && $result['game'] !== $game)) {
                    throw new InvalidArgumentException('請指定一個項目：game=lol、cs、val、mlb 或 nba。');
                }
                $result['game'] = $game;
            } elseif (str_starts_with(mb_strtolower($token), 'page=')) {
                $value = substr($token, 5);
                if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 100) {
                    throw new InvalidArgumentException('頁碼必須介於 1～100。');
                }
                $result['page'] = (int) $value;
            } else {
                $parts[] = $token;
            }
        }
        $last = $parts[array_key_last($parts)] ?? '';
        if (preg_match('/^(?:\d{4}|\d{1,2}[\/-]\d{1,2}|\d{4}[\/-]\d{1,2}[\/-]\d{1,2}|今天|明天|後天|昨天)$/u', $last)) {
            $result['date'] = $this->date($last);
            array_pop($parts);
        }
        $target = trim(implode(' ', $parts));
        if ($target === '' || mb_strlen($target) > 350) {
            throw new InvalidArgumentException('請輸入隊名或 Stake 比賽網址。');
        }
        if (mb_strtolower($target) === 'teams') {
            $result['team'] = 'teams';
        } elseif (preg_match('/^[2-9A-HJKMNP-Z]{5}$/i', $target) && ! StakeOddsCatalog::isKnownTeam($target)) {
            $result['code'] = strtoupper($target);
        } elseif (preg_match('/^id=([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $target, $m)) {
            $result['id'] = $m[1];
        } elseif (preg_match('~^https?://~i', $target)) {
            $url = parse_url($target);
            $hosts = ['stake.com'];
            foreach ((app(StakeApiSourceConfig::class)->load()['sources'] ?? []) as $source) {
                $hosts[] = parse_url($source['url'], PHP_URL_HOST);
            }
            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
                || isset($url['port']) || ! in_array(strtolower($url['host'] ?? ''), $hosts, true)
                || ! preg_match('~^/(?:[a-z]{2}(?:-[A-Za-z]{2})?/)?sports/(league-of-legends|counter-strike|valorant|baseball|basketball)/([a-zA-Z0-9-]+)/([a-zA-Z0-9-]+)/([a-zA-Z0-9-]+)/?$~', $url['path'] ?? '', $m)) {
                throw new InvalidArgumentException('請使用 Stake 的 LoL、CS2、VAL、MLB 或 NBA 單場比賽網址。');
            }
            $urlGame = StakeOddsCatalog::fixtureGame(['tournament' => ['slug' => $m[3],
                'category' => ['slug' => $m[2], 'sport' => ['slug' => $m[1]]]]]);
            if ($urlGame === null || ($result['game'] !== null && $result['game'] !== $urlGame)) {
                throw new InvalidArgumentException('網址的項目或聯盟不在支援範圍，或與 game= 不符。');
            }
            $result['game'] = $urlGame;
            // Only the slug is passed as a GraphQL variable. Never fetch user URLs.
            $result['slug'] = $m[4];
        } else {
            if (str_contains($target, '=') || preg_match('/[\/~]/u', $target) || mb_strlen($target) > 80) {
                throw new InvalidArgumentException('隊名或日期格式不正確。');
            }
            $result['team'] = $target;
        }

        return $result;
    }

    private function date(string $value): CarbonImmutable
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $relative = ['今天' => 0, '明天' => 1, '後天' => 2, '昨天' => -1];
        if (isset($relative[$value])) {
            return $today->addDays($relative[$value]);
        }
        if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [$today->year, (int) $m[1], (int) $m[2]];
        } else {
            [$year, $month, $day] = [$today->year, (int) substr($value, 0, 2), (int) substr($value, 2, 2)];
        }
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('日期不存在，請確認月份與日期。');
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);
    }

    private function findFixtures(string $team, ?CarbonImmutable $date, ?string $game = null): array
    {
        $pending = $game === null ? StakeOddsCatalog::GAMES : [$game => StakeOddsCatalog::GAMES[$game]];
        $aliases = [];
        foreach ($pending as $key => $definition) {
            $aliases[$key] = StakeOddsCatalog::aliases($team, $key);
        }
        $matches = [];
        $now = CarbonImmutable::now(self::TIMEZONE);
        for ($offset = 0; $offset < 1000; $offset += 50) {
            // Batch sports in one request, then paginate only unfinished lists.
            $fields = [];
            foreach ($pending as $key => $definition) {
                $fields[] = $key.': slugSport(sport: "'.$definition['sport'].'") {'
                    .' upcomingCount: fixtureCount(type: upcoming) liveCount: fixtureCount(type: live)'
                    .' upcoming: fixtureList(type: upcoming, limit: $limit, offset: $offset) { '.self::FIXTURE_FIELDS.' }'
                    .' live: fixtureList(type: live, limit: $limit, offset: $offset) { '.self::FIXTURE_FIELDS.' } }';
            }
            $query = 'query StakeOddsFixtures($limit: Int!, $offset: Int!) { '.implode(' ', $fields).' }';
            $response = $this->api->query('StakeOddsFixtures', $query, ['limit' => 50, 'offset' => $offset]);
            foreach ($pending as $key => $definition) {
                $sport = $response['data'][$key] ?? null;
                if (! empty($response['errors']) || ! is_array($sport)
                    || ! is_array($sport['upcoming'] ?? null) || ! is_array($sport['live'] ?? null)
                    || ! is_int($sport['upcomingCount'] ?? null) || ! is_int($sport['liveCount'] ?? null)) {
                    // An incomplete cross-sport lookup must not silently choose the wrong game.
                    throw new RuntimeException('Invalid Stake fixture list.');
                }
                foreach (['upcoming', 'live'] as $type) {
                    foreach ($sport[$type] as $fixture) {
                        $startAt = $this->startAt($fixture);
                        if (StakeOddsCatalog::fixtureGame($fixture) !== $key
                            || $startAt === null || ! is_string($fixture['id'] ?? null)
                            || ! in_array($fixture['status'] ?? '', ['active', 'live', 'suspended'], true)
                            || ($date !== null && ! $startAt->isSameDay($date))
                            || ($type === 'upcoming' && $startAt->lessThan($now))) {
                            continue;
                        }
                        foreach ($fixture['data']['competitors'] ?? [] as $competitor) {
                            $name = StakeOddsCatalog::normalize($competitor['name'] ?? '');
                            $short = StakeOddsCatalog::normalize($competitor['abbreviation'] ?? '');
                            if ($team === '' || in_array($name, $aliases[$key], true) || ($short !== '' && in_array($short, $aliases[$key], true))) {
                                $matches[$fixture['id']] = [...$fixture, '_start_at' => $startAt,
                                    '_is_live' => $type === 'live', '_team' => $name, '_game' => $key];
                            }
                        }
                    }
                }
                if ($offset + 50 >= max($sport['upcomingCount'], $sport['liveCount'])) {
                    unset($pending[$key]);
                } elseif ($sport['upcoming'] === [] && $sport['live'] === []) {
                    throw new RuntimeException('Incomplete Stake fixture pagination.');
                }
            }
            if ($pending === []) {
                $matches = array_values($matches);
                usort($matches, fn (array $a, array $b): int => ($b['_is_live'] <=> $a['_is_live'])
                    ?: ($a['_start_at'] <=> $b['_start_at']) ?: strcmp($a['id'], $b['id']));

                return $matches;
            }
        }

        throw new RuntimeException('Stake fixture list exceeds the query limit.');
    }

    private function fixture(?string $id, ?string $slug): ?array
    {
        $operation = $slug === null ? 'StakeFixtureOdds' : 'StakeFixtureOddsBySlug';
        $field = $slug === null ? 'sportFixture(fixtureId: $value)' : 'sportFixture: slugFixture(fixture: $value)';
        $query = 'query '.$operation.'($value: String!) { '.$field.' { '.self::FIXTURE_FIELDS.' '.self::MARKET_FIELDS.' } }';
        $response = $this->api->query($operation, $query, ['value' => $slug ?? $id]);
        if (! empty($response['errors']) || ! is_array($response['data'] ?? null) || ! array_key_exists('sportFixture', $response['data'])) {
            throw new RuntimeException('Invalid Stake odds response.');
        }
        $fixture = $response['data']['sportFixture'];
        if ($fixture !== null && (! is_array($fixture) || ! is_array($fixture['groups'] ?? null)
            || ($id !== null && ($fixture['id'] ?? null) !== $id)
            || ($slug !== null && ($fixture['slug'] ?? null) !== $slug))) {
            throw new RuntimeException('Stake fixture identity does not match.');
        }

        return $fixture;
    }

    private function markets(array $fixture): array
    {
        $markets = [];
        foreach (['winner' => '獨贏', 'handicap' => '讓分', 'total' => '大小分'] as $groupName => $label) {
            foreach ($fixture['groups'] as $group) {
                if (strtolower($group['name'] ?? '') !== $groupName) {
                    continue;
                }
                foreach ($group['templates'] ?? [] as $template) {
                    foreach ($template['markets'] ?? [] as $market) {
                        if (! is_string($market['id'] ?? null) || isset($markets[$market['id']])
                            || ! in_array($market['status'] ?? '', ['active', 'suspended'], true)
                            || preg_match('/(?:^|\|)(?:map|quarternr|inningnr|periodnr|halfnr|setnr)=/i', $market['specifiers'] ?? '')) {
                            continue;
                        }
                        $outcomes = [];
                        foreach ($market['outcomes'] ?? [] as $outcome) {
                            $price = $outcome['odds'] ?? null;
                            $available = ($fixture['status'] ?? '') !== 'suspended' && $market['status'] === 'active'
                                && ($outcome['active'] ?? false) === true && is_numeric($price) && is_finite((float) $price) && (float) $price > 1;
                            $name = ChineseConverter::toTraditional((string) ($outcome['name'] ?? ''));
                            $name = preg_replace(['/^Over\s+/i', '/^Under\s+/i'], ['大於 ', '小於 '], $name);
                            $decimal = $available ? rtrim(number_format((float) $price, 6, '.', ''), '0') : '';
                            if ($available) {
                                $decimal = str_pad($decimal, strpos($decimal, '.') + 3, '0');
                            }
                            $outcomes[] = ['name' => $name, 'price' => $available ? $decimal : '暫停', 'available' => $available];
                        }
                        if ($outcomes !== []) {
                            $markets[$market['id']] = [
                                'id' => $market['id'], 'group_label' => $label,
                                'name' => str_replace('比賽地圖數', '總局數大小分', ChineseConverter::toTraditional($market['name'] ?? '')),
                                'outcomes' => $outcomes,
                            ];
                        }
                    }
                }
            }
        }

        return array_values($markets);
    }

    private function choices(array $matches, bool $textOnly = false): LineBotReply
    {
        $lines = ['找到多場符合的賽事，請點選或使用該場遊戲編號查詢（台灣時間）：'];
        $quick = [];
        foreach (array_slice($matches, 0, 10) as $index => $fixture) {
            $lookup = $this->code($fixture['id']) ?? 'id='.$fixture['id'];
            $label = StakeOddsCatalog::GAMES[$fixture['_game']]['label'];
            $command = '!odds '.$lookup.($textOnly ? ' text' : '');
            $lines[] = ($index + 1).'. 【'.$label.'】'.$fixture['_start_at']->format('m/d H:i').' '
                .ChineseConverter::toTraditional($fixture['name']).($fixture['_is_live'] ? '｜滾球中' : '');
            $lines[] = $command;
            $quick[] = ['label' => ($index + 1).'. '.$label.' '.$fixture['_start_at']->format('m/d H:i'), 'text' => $command];
        }
        if (count($matches) > 10) {
            $lines[] = '僅列前 10 場，請加上 game=、日期或使用 Stake 比賽網址縮小範圍。';
        }

        return new LineBotReply(implode("\n", $lines), null, null, $quick);
    }

    private function fixtureUrl(array $fixture): string
    {
        $tournament = $fixture['tournament'];

        return 'https://stake.com/zh/sports/'.rawurlencode($tournament['category']['sport']['slug']).'/'.rawurlencode($tournament['category']['slug'])
            .'/'.rawurlencode($tournament['slug']).'/'.rawurlencode($fixture['slug']);
    }

    private function startAt(array $fixture): ?CarbonImmutable
    {
        $value = $fixture['data']['startTime'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    /** Match both opponents and start time; a shared team name is insufficient. */
    public function enrichSchedule(array $matches): array
    {
        $eligible = array_filter($matches, fn (array $m): bool => ($m['game'] ?? '') === 'lol'
            && ! ($m['is_finished'] ?? false) && ! ($m['is_cancelled'] ?? false));
        if ($eligible === [] || ! filled(config('services.stake.access_token'))) {
            return $matches;
        }
        try {
            $fixtures = $this->findFixtures('', null, 'lol');
            foreach ($eligible as $index => $match) {
                $left = StakeOddsCatalog::aliases($match['team1'], 'lol');
                $right = StakeOddsCatalog::aliases($match['team2'], 'lol');
                $candidates = [];
                foreach ($fixtures as $fixture) {
                    $teams = $fixture['data']['competitors'] ?? [];
                    if (count($teams) !== 2 || abs($fixture['_start_at']->diffInMinutes($match['start_at'], false)) > 15) {
                        continue;
                    }
                    $first = array_map(StakeOddsCatalog::normalize(...), [$teams[0]['name'] ?? '', $teams[0]['abbreviation'] ?? '']);
                    $second = array_map(StakeOddsCatalog::normalize(...), [$teams[1]['name'] ?? '', $teams[1]['abbreviation'] ?? '']);
                    if ((array_intersect($left, $first) !== [] && array_intersect($right, $second) !== [])
                        || (array_intersect($left, $second) !== [] && array_intersect($right, $first) !== [])) {
                        $candidates[] = $fixture;
                    }
                }
                if (count($candidates) === 1) {
                    $matches[$index]['stake_code'] = $this->code($candidates[0]['id']);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Stake schedule fixture matching unavailable.', ['type' => $exception::class]);
        }

        return $matches;
    }

    private function code(string $fixtureId): ?string
    {
        try {
            $code = app(StakeFixtureCodes::class)->forFixture($fixtureId);

            // Team names such as HAWKS and the TEAMS command take precedence.
            // Use the fixture ID if a generated code happens to be a reserved word.
            return strtolower($code) === 'teams' || StakeOddsCatalog::isKnownTeam($code) ? null : $code;
        } catch (Throwable $exception) {
            Log::warning('Stake fixture code storage unavailable.', ['type' => $exception::class]);

            return null;
        }
    }
}
