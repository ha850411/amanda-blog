<?php

namespace App\Services;

class LineHelpService
{
    public function reply(string $argument = ''): LineBotReply
    {
        $tokens = preg_split('/\s+/u', trim(mb_strtolower($argument)), -1, PREG_SPLIT_NO_EMPTY);
        $textOnly = in_array('text', $tokens, true);
        $target = implode(' ', array_values(array_diff($tokens, ['text'])));
        $pages = $this->pages();
        $keys = array_keys($pages);
        $aliases = ['總覽' => 'overview', '賽程' => 'schedule', '篩選' => 'filters', '賠率' => 'odds',
            '玩法' => 'markets', '投注' => 'bets', '紀錄' => 'records', '記錄' => 'records', '日期' => 'dates', '水位' => 'balance'];
        foreach (LineCommandRegistry::COMMANDS as $definition) {
            foreach ($definition['aliases'] as $alias) {
                $aliases[$alias] = $definition['page'];
            }
        }
        $key = $target === '' ? 'overview' : ($aliases[$target] ?? $target);
        if (ctype_digit($target)) {
            $key = $keys[(int) $target - 1] ?? '';
        }
        if (! isset($pages[$key])) {
            return new LineBotReply('找不到這個說明頁。請輸入 !h，或 !h 1～'.count($pages).'；加 text 可看文字版。', null, null, [
                ['label' => '指令總覽', 'text' => '!h'],
            ]);
        }
        $index = array_search($key, $keys, true);
        $data = ['type' => 'help', 'title' => 'LINE Bot 指令手冊', 'topic' => $pages[$key]['title'],
            'page' => $index + 1, 'pages' => count($pages), 'sections' => $pages[$key]['sections']];
        $suffix = $textOnly ? ' text' : '';
        $quick = [];
        if ($index > 0) {
            $quick[] = ['label' => '上一頁', 'text' => '!h '.$index.$suffix];
        }
        if ($index + 1 < count($pages)) {
            $quick[] = ['label' => '下一頁', 'text' => '!h '.($index + 2).$suffix];
        }
        foreach ($pages as $id => $page) {
            if ($id !== $key) {
                $quick[] = ['label' => $page['title'], 'text' => '!h '.$id.$suffix];
            }
        }
        $quick[] = ['label' => $textOnly ? '圖片版' : '文字版', 'text' => '!h '.$key.($textOnly ? '' : ' text')];

        return new LineBotReply($this->text($key), null, $textOnly ? null : $data, $quick);
    }

    public function text(string $key): string
    {
        $pages = $this->pages();
        $page = $pages[$key];
        $number = array_search($key, array_keys($pages), true) + 1;
        $lines = ['LINE Bot 指令手冊｜'.$page['title'].'｜'.$number.'/'.count($pages), '指令格式：'];
        foreach ($page['sections'] as $section) {
            $lines[] = "\n【".$section['title'].'】';
            array_push($lines, ...$section['lines']);
        }
        $lines[] = "\n!h 查看總覽｜!h 頁碼 翻頁｜!h {$key} text 查看本頁文字版";

        return implode("\n", $lines);
    }

    /** Keep examples, defaults, bounds and aliases synchronized with their parsers. */
    public function pages(): array
    {
        $scheduleDays = LineScheduleBot::MAX_SCHEDULE_DAYS;
        $scheduleLimit = LineScheduleBot::MAX_SCHEDULE_MATCHES;
        $historyDays = StakeBetService::MAX_HISTORY_DAYS;
        $historyLimit = StakeBetService::MAX_DISPLAY_RECORD_BETS;
        $activeLimit = StakeBetService::MAX_ACTIVE_BETS;
        $oddsPageSize = StakeOddsService::PAGE_SIZE;
        $oddsChoices = StakeOddsService::MAX_CHOICES;
        $balanceDays = StakeBetService::DEFAULT_BALANCE_DAYS;
        $balanceMax = StakeBetService::MAX_BALANCE_DAYS;
        $section = static fn (string $title, array $lines): array => compact('title', 'lines');
        $overview = [];
        foreach (LineCommandRegistry::COMMANDS as $group => $definition) {
            $overview[] = $section($definition['label'], [LineCommandRegistry::names($group), '詳細用法：!h '.$definition['page']]);
        }
        $overview[] = $section('閱讀方式', [
            '!h 與 !help 都會開啟這份圖片手冊；點下方分類或上一頁／下一頁查看。',
            '!h 2｜!help odds｜!h odds text（文字版）',
            '在 LINE 對話中輸入指令。[] 代表可省略，輸入時不用加括號；參數以空格分開。',
            '英文指令不分大小寫；支援全形驚嘆號。一般訊息與無效指令通常不回覆。',
            '日期以台灣時間為準，未填年份使用今年。圖片無法產生時會改用文字回覆。',
        ]);

        return [
            'overview' => ['title' => '指令總覽', 'sections' => $overview],
            'schedule' => ['title' => '賽程查詢', 'sections' => [
                $section('指令與別名', [LineCommandRegistry::names('schedule'),
                    '!match／!matches／!賽程／!schedule：綜合五項目；!lol、!val、!cs／!cs2、!mlb、!nba：指定項目。']),
                $section('基本格式', ['!match [日期或區間] [篩選參數] [text]',
                    '!match｜!lol 明天｜!val 後天｜!cs 11/07｜!nba 明天 team=勇士',
                    '未填日期預設今天；日期要放在篩選參數之前。篩選說明請看 !h filters。']),
                $section('單日日期', ['今天、明天、後天；1107、11/07、11-07、11.07；2026-11-07、2026/11/07、2026.11.07。',
                    '!lol 1107｜!nba 2026-11-07']),
                $section('多日賽程', ["兩端日期以 ~ 或 ～ 分隔，包含起訖兩天，最多 {$scheduleDays} 天；反向日期會自動交換。",
                    '!match 1107~1109｜!lol 11/07~11/09',
                    "1d～{$scheduleDays}d 表示從今天起算的天數，含今天；!match 2d 查今天與明天。"]),
                $section('賽程會顯示什麼', ['今天保留滾球與尚未開賽的場次；已完賽、取消或延期場次依各來源狀態排除。',
                    '顯示開賽時間、對戰、賽制、可取得的比分、獨贏賠率與近 5 場；電競另有交手紀錄。',
                    '五項目皆會比對 Stake；能唯一匹配雙方隊伍與時間時顯示遊戲編號。用 !odds 編號 查詳細盤口。',
                    '沒有編號仍可用 !odds 隊名 game=項目 查詢；資料來源暫時無法取得時會提示。']),
            ]],
            'filters' => ['title' => '賽程篩選', 'sections' => [
                $section('game= 項目', ['lol、val／valorant、cs／cs2、mlb、nba；可用 / 或逗號合併多個項目。',
                    '!match 明天 game=lol/cs｜!match 2d game=mlb,nba',
                    '單項目指令也接受 game=，填入後會以此選項決定查詢項目。']),
                $section('tier= 賽事等級', ['電競預設 tier=s；支援 s、a、b、c、d，逗號可複選，tier=all 查全部等級。',
                    '!cs 明天 tier=s,a｜!val 1107 tier=all', 'MLB 與 NBA 不套用 tier 篩選。']),
                $section('team= 隊名與 limit= 場數', ['team= 以名稱關鍵字篩選；名稱含空格時用雙引號。',
                    '!match 明天 team=G2｜!nba 明天 team=勇士',
                    '!match 明天 team="Team Liquid" limit=5',
                    "limit= 支援 1～{$scheduleLimit}，預設 {$scheduleLimit} 場，在隊名篩選後套用。"]),
                $section('追蹤隊伍', ['MLB 目前追蹤：'.$this->trackedTeams('mlb').'。',
                    'MLB 查今天會另外納入所有球隊的滾球；未開賽與其他日期仍依追蹤名單。',
                    'NBA 目前追蹤：'.$this->trackedTeams('nba').'。',
                    'NBA 所有日期與狀態都依追蹤名單；只要任一隊被追蹤即納入。team= 會再縮小範圍。']),
                $section('文字回覆與完整範例', ['加 text 改用純文字：!cs 明天 tier=s,a team=G2 limit=5 text',
                    '賽程下方可點日期與項目快捷按鈕；文字模式會隨賽程導覽保留。']),
            ]],
            'odds' => ['title' => '賠率查詢', 'sections' => [
                $section('指令與格式', [LineCommandRegistry::names('odds'), '!odds 隊名 [日期] [game=項目] [text]',
                    'Stake 盤口｜LoL／CS2／VAL／MLB／NBA；每次查詢重新取得資料。',
                    '滾球比賽的圖片與文字會列出目前比分，順序與上方隊伍相同；詳細說明見 !h markets。']),
                $section('項目與範例', ['game= 一次指定一個：lol、cs／cs2、val／valorant、mlb、nba；也接受 league-of-legends、counter-strike。',
                    '!odds G2 game=lol｜!odds G2 game=cs｜!odds G2 game=val',
                    '!odds 勇士 game=nba｜!odds 道奇 明天 game=mlb']),
                $section('日期與參數順序', ['日期可用今天、明天、後天、昨天；1107、11/07、11-07；2026-11-07 或 2026/11/07。',
                    '只指定單日，以台灣開賽日期比對。日期放隊名後；game=、text、page= 可放日期前後。',
                    '!odds G2 game=lol 1107｜!odds BLG 11/06',
                    '!odds T1 1107｜!odds T1']),
                $section('省略項目或日期', ['省略 game= 會跨五項目搜尋；G2 跨遊戲同名、或勇士同時符合 NBA／MLB 時，先列出場次讓你選擇。',
                    '同隊同項目省略日期：進行中優先，再找最近一場未開賽。指定日期有多場（含 MLB 雙重賽）會列出選項。',
                    "選單顯示項目、對手、台灣時間及短碼，最多 {$oddsChoices} 場；可加 game= 或日期縮小範圍。"]),
                $section('隊名清單', ['!odds teams 或 !odds teams game=lol：LoL 隊名。',
                    '!odds teams game=nba｜!odds teams game=mlb',
                    '!odds teams game=cs｜!odds teams game=val：隊名查詢方式。',
                    'CS2 隊名可用 BetBoom 或 BetBoom Team，例如 !odds BetBoom game=cs。',
                    '支援明確全名、簡稱與別名，如 BLG／bili／bilibili；不模糊合併二隊。NBA／MLB 不受賽程追蹤名單限制。']),
            ]],
            'markets' => ['title' => '盤口與選場', 'sections' => [
                $section('提供的玩法', ['電競：整場獨贏、地圖讓分、總局數大小分。NBA／MLB：整場獨贏、讓分、總分大小分。',
                    '保留盤口本身的延長賽／延長局規則；僅顯示 Stake 目前提供的盤口，不補其他莊家或歷史賠率。']),
                $section('盤口狀態', ['查詢僅包含上述整場玩法；暫停的選項顯示「暫停」，未開放的類別顯示「暫無盤口」。']),
                $section('滾球比分', ['電競顯示系列賽比分；有資料時另列 LoL 當局擊殺、CS2／VAL 當局回合。',
                    'NBA／MLB 顯示總比分；圖片與文字的比分順序皆與上方隊伍相同。',
                    '目前比分未提供時標示「暫無資料」；未開賽不顯示比分。']),
                $section('精準指定場次', ['!odds K7M2Q（以賽程或選單給的遊戲編號取代）',
                    '!odds id=比賽UUID｜!odds 貼上Stake單場比賽網址',
                    'LoL、CS2、VAL、NBA、MLB 網址皆可；加 game= 時必須與該場項目一致。短碼過期請重查賽程或改用隊名／網址。']),
                $section('翻頁與文字版', ["每頁最多 {$oddsPageSize} 個盤口，有多頁才顯示上一頁／下一頁。",
                    '!odds K7M2Q page=2｜!odds T1 1107 text',
                    'page= 為正整數，實際頁數以回覆為準；選場與翻頁會保留 text 文字模式。',
                    '要重新取得賠率與比分，重送同一指令即可。加 text 可複製完整盤口文字。']),
            ]],
            'bets' => ['title' => '投注與即時餘額', 'sections' => [
                $section('指令與別名', [LineCommandRegistry::names('bets'), '!bet help 或 !h bets 查看本頁。']),
                $section('進行中注單', ['!bet：讀取機器人所設定 Stake 帳號的進行中體育投注，顯示即時賽況、金額、兌現資訊與資金水位。',
                    "!bet 5：限制顯示筆數；預設與上限都是 {$activeLimit} 筆，超過會取上限。",
                    '!bet text／!bet txt／!bet 文字：文字版。筆數與文字參數請擇一使用。',
                    '這些是查詢指令，顯示兌現資訊不會替你執行下注或兌現。']),
                $section('即時帳號餘額', ['!bet balance：查看 USDT 即時資金水位（文字）。',
                    'balance 可換成 bal、水位、資金、餘額、usdt；例如 !bet 餘額。']),
                $section('轉查紀錄或水位圖', ['!bet record 7d｜!bet history 昨天｜!bet 7d：投注紀錄，等同 !r 對應日期。',
                    '紀錄子指令另有 records、紀錄、記錄、歷史、損益、pnl。',
                    '!bet balance 7d：近 7 天水位圖，等同 !balance 7d。',
                    '水位圖子指令另有 bal、水位、資金水位、資金、chart，後面需接天數。',
                    '!bet 7 表示最多 7 筆進行中注單；!r 7 表示近 7 天紀錄。']),
            ]],
            'records' => ['title' => '投注紀錄與損益', 'sections' => [
                $section('指令與格式', [LineCommandRegistry::names('records'), '!r 或 !record [日期或區間] [text]',
                    '預設今天，顯示查詢區間的注單紀錄與損益統計圖；詳細日期別名見 !h dates。']),
                $section('支援範例：', ['!r｜!r 昨天｜!r 1107｜!r 11/07｜!r 2026-11-07',
                    '!r 11/01~11/07｜!r 近7天｜!r 7d｜!r 7',
                    '!r 本週｜!r 上週｜!r 本月｜!r 上月',
                    '!r 7d text（也接受 txt 或 文字）']),
                $section('賽事比分', ['獲勝與未中獎都列出比賽比分；串關逐場列出，圖片與文字相同。',
                    '比分順序與對戰隊伍相同；電競為系列賽比分，NBA／MLB 為總比分。',
                    '顯示 Stake 提供的比分；來源未提供完整比分時標示「暫無資料」。']),
                $section('範圍與限制', ["區間含起訖日期，最多 {$historyDays} 天；超過會提示縮小範圍。注單明細最多顯示 {$historyLimit} 筆。",
                    '近 N 天含今天並往回算；本週從週一起算到今天，上週為完整上週。',
                    "本月到今天、上月為完整月份；若該區間超過 {$historyDays} 天，保留區間最後 {$historyDays} 天。",
                    '一週為含今天近 7 天，一個月為含今天近 30 天。反向日期會自動交換。']),
                $section('相關查詢', ['!bet record 7d：同樣查紀錄；!balance 7d：資金水位走勢。',
                    '!r help／!record help 與 !h records 使用相同說明內容。']),
            ]],
            'dates' => ['title' => '紀錄日期格式', 'sections' => [
                $section('適用範圍', ['本頁適用 !r／!record 與 !bet 的紀錄查詢。賽程請看 !h schedule，賠率請看 !h odds。']),
                $section('單日格式', ['1107、11/07、11-07、11.07、11月7日、11月7號（中文日／號可省略）。',
                    '2026-11-07、2026/11/07、2026.11.07、20261107、2026年11月7日／號。',
                    '今天／今日／today；昨天／昨日／yesterday；前天／前日／before_yesterday。',
                    '!r 11月7日｜!r yesterday']),
                $section('日期區間', ['兩端可以使用上述日期；分隔符支援 ~、～、至、到、..、空格-空格。',
                    '!r 1101~1107｜!r 11/01至11/07｜!r 11-01 - 11-07',
                    '也接受 !r 1101-1107、!r 11-01-11-07。']),
                $section('最近幾天', ["N 為 1～{$historyDays}；可用 N、Nd、Ndays、N天、N日，並可在有單位時加近或過去。",
                    '!r 7｜!r 7d｜!r 7days｜!r 近7天｜!r 過去7日',
                    '一週／一周＝近 7 天；一個月／一个月＝近 30 天。']),
                $section('週與月份別名', ['本週／本周／this week／thisweek／week',
                    '上週／上周／last week／lastweek',
                    '本月／this month／thismonth／month；上月／last month／lastmonth。',
                    '!r last week text｜!r 本月 text']),
            ]],
            'balance' => ['title' => '資金水位走勢', 'sections' => [
                $section('指令與格式', [LineCommandRegistry::names('balance'), '!balance 或 !bal 或 !水位 [天數] [text]',
                    "預設回傳近 {$balanceDays} 天，最多可查詢 {$balanceMax} 天。"]),
                $section('支援範例：', ['!balance｜!bal 7｜!bal 7d｜!bal 7天｜!水位 30d',
                    '!balance 近7天｜!balance 7日｜!balance 3d text',
                    'text 也可用 txt 或 文字；從今天往回計算，含今天。',
                    "大於 {$balanceMax} 天會改查 {$balanceMax} 天並提示；小於 1 天以 1 天計算。"]),
                $section('圖表怎麼看', ['依注單結盤時間繪製資金水位；7 天內逐筆呈現，超過 7 天按日彙整。',
                    '可取得時會顯示帳號餘額與進行中投注資訊；資料更新可能有短暫快取。']),
                $section('即時餘額與歷史圖', ['!bet balance 查當下 USDT 餘額；!balance 查一段時間的水位走勢。',
                    '!bet balance 7d 也可查近 7 天水位圖；!r 7d 查看同期間投注紀錄與損益。',
                    '!balance help／!h balance 查看本頁。']),
            ]],
        ];
    }

    private function trackedTeams(string $game): string
    {
        $names = config($game.'.team_names', []);
        $teams = array_map(fn ($id): string => $names[$id] ?? (string) $id, config($game.'.team_ids', []));

        return $teams === [] ? '未啟用追蹤' : implode('、', $teams);
    }
}
