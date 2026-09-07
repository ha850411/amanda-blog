<?php

namespace Tests\Feature;

use App\Services\LineScheduleBot;
use App\Services\StakeBetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StakeBetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-06 12:00:00');

        config([
            'services.stake.access_token' => 'test-stake-token-123',
            'services.stake.api_url' => 'https://stake.com/_api/graphql',
            'services.bo3.timezone' => 'Asia/Taipei',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_bet_command_returns_formatted_bets_when_active_bets_exist(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($this->sampleActiveSportBetsResponse(), 200)
                ->push($this->sampleUserBalancesResponse(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $text = $reply->text;

        $this->assertStringContainsString('Stake 體育投注｜進行中注單（3 筆）', $text);
        $this->assertStringContainsString('時間基準｜台灣時間', $text);

        // Bet 1 (2 關串關)
        $this->assertStringContainsString('【注單 1】2 關串關｜#649557501', $text);
        $this->assertStringContainsString('・投注：71.8 USDT', $text);
        $this->assertStringContainsString('・總賠率：2.145｜預估返還：154.01 USDT', $text);
        $this->assertStringContainsString('・即時兌現：92.41 USDT（1.287x）', $text);
        $this->assertStringContainsString('・下注時間：09/06 14:25', $text);
        $this->assertStringContainsString('關卡 1/2｜進行中 ⏳', $text);
        $this->assertStringContainsString('【英雄聯盟】LCK 2026 Season Playoffs', $text);
        $this->assertStringContainsString('T1 Esports vs Dplus KIA', $text);
        $this->assertStringContainsString('賽況：滾球中（0-0，一號地圖）', $text);
        $this->assertStringContainsString('選項：T1 Esports @ 1.65（比賽獲勝者 - Two 路線）', $text);
        $this->assertStringContainsString('關卡 2/2｜已過 ✅', $text);
        $this->assertStringContainsString('選項：Over 3.5 @ 1.30（比賽地圖數）', $text);

        // Bet 2 (單注)
        $this->assertStringContainsString('【注單 2】單注｜#649549583', $text);
        $this->assertStringContainsString('・投注：30 USDT', $text);
        $this->assertStringContainsString('・總賠率：1.600｜預估返還：48 USDT', $text);
        $this->assertStringContainsString('・即時兌現：29.7 USDT（0.990x）', $text);
        $this->assertStringContainsString('狀態：進行中 ⏳', $text);
        $this->assertStringContainsString('Invictus Gaming vs Team WE', $text);

        // Bet 3 (4 關串關)
        $this->assertStringContainsString('【注單 3】4 關串關｜#649549533', $text);
        $this->assertStringContainsString('・投注：50 USDT', $text);
        $this->assertStringContainsString('・總賠率：5.053｜預估返還：252.65 USDT', $text);
        $this->assertStringContainsString('關卡 2/4｜進行中 ⏳', $text);
        $this->assertStringContainsString('【無畏契約】VCT 2026：太平洋賽區第二階段', $text);
        $this->assertStringContainsString('Nongshim RedForce vs Global Esports', $text);
        $this->assertStringContainsString('賽況：16:00 開賽', $text);

        // Summary footer
        $this->assertStringContainsString('總計 3 筆注單｜總投注：151.8 USDT', $text);
        $this->assertStringContainsString('資金水位｜可用：0.0083 USDT', $text);
        $this->assertStringContainsString('完整注單｜https://stake.com/zh/my-bets/sports', $text);

        // Image support assertions
        $this->assertTrue($reply->prefersImage());
        $this->assertSame('https://stake.com/zh/my-bets/sports', $reply->linkUrl);
        $this->assertNotNull($reply->imageData);
        $this->assertSame('bets', $reply->imageData['type']);
        $this->assertCount(3, $reply->imageData['bets']);
        $this->assertSame('英雄聯盟', $reply->imageData['bets'][0]['legs'][0]['sport_name']);
        $this->assertSame('無畏契約', $reply->imageData['bets'][2]['legs'][1]['sport_name']);
        $this->assertSame('92.41 USDT（1.287x）', $reply->imageData['bets'][0]['cashout_formatted']);
        $this->assertFalse($reply->imageData['bets'][0]['cashout_disabled']);
        $this->assertSame(1.287, $reply->imageData['bets'][0]['cashout_multiplier']);
        $this->assertSame('0.0083 USDT', $reply->imageData['balance_formatted']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://stake.com/_api/graphql'
                && $request->header('x-access-token')[0] === 'test-stake-token-123'
                && $request->header('x-operation-name')[0] === 'ActiveBetCount_User';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://stake.com/_api/graphql'
                && $request->header('x-access-token')[0] === 'test-stake-token-123'
                && $request->header('x-operation-name')[0] === 'UserBalances';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://stake.com/_api/graphql'
                && $request->header('x-access-token')[0] === 'test-stake-token-123'
                && $request->header('x-operation-name')[0] === 'FetchActiveSportBets'
                && $request['variables']['limit'] === 20;
        });
    }

    public function test_bet_command_handles_zero_active_bets(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response([
                'data' => [
                    'user' => [
                        'activeSportBetCount' => 0,
                        'activeSwishBetCount' => 0,
                        'activeRacingBetCount' => 0,
                        'activeSportsbookXMultiBetCount' => 0,
                    ],
                ],
            ], 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('目前無進行中的 Stake 體育注單。', $reply->text);

        Http::assertSentCount(2);
        Http::assertNotSent(function ($request): bool {
            return $request->header('x-operation-name')[0] === 'FetchActiveSportBets';
        });
    }

    public function test_bet_command_handles_missing_access_token(): void
    {
        config(['services.stake.access_token' => '']);
        Http::fake();

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('尚未設定 Stake Access Token', $reply->text);
        Http::assertNothingSent();
    }

    public function test_bet_command_handles_401_unauthorized(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertStringContainsString('Stake 認證失敗', $reply->text);
    }

    public function test_bet_command_handles_403_forbidden(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertStringContainsString('Stake API 存取受限', $reply->text);
    }

    public function test_bet_command_handles_451_legal_reasons(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response(['message' => 'Unavailable for legal reasons'], 451),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertStringContainsString('區域限制 HTTP 451', $reply->text);
    }

    public function test_bet_command_handles_500_server_error(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response(['message' => 'Server error'], 500),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertStringContainsString('目前無法取得 Stake 投注資訊', $reply->text);
    }

    public function test_bet_command_handles_connection_exception_without_proxy(): void
    {
        config(['services.stake.proxy' => null]);
        Http::fake([
            'https://stake.com/_api/graphql' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertSame('連線至 Stake 伺服器超時，請稍後再試。', $reply->text);
    }

    public function test_bet_command_handles_connection_exception_with_proxy(): void
    {
        config(['services.stake.proxy' => 'http://127.0.0.1:8080']);
        Http::fake([
            'https://stake.com/_api/graphql' => function () {
                throw new ConnectionException('Failed to connect to proxy');
            },
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertSame('連線至 Stake 代理伺服器失敗或超時，請檢查 STAKE_PROXY 設定後再試。', $reply->text);
    }

    public function test_bet_command_case_and_unicode_variations(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response([
                'data' => ['user' => ['activeSportBetCount' => 0]],
            ], 200),
        ]);

        $aliases = ['!bet', '!BET', '!bets', '!BETS', '!stake', '!STAKE', '!投注', '！ｂｅｔ'];

        foreach ($aliases as $alias) {
            $reply = app(LineScheduleBot::class)->reply($alias);
            $this->assertNotNull($reply, "Failed asserting that alias {$alias} was handled.");
            $this->assertStringContainsString('目前無進行中的 Stake 體育注單。', $reply->text);
        }
    }

    public function test_bet_help_command(): void
    {
        $reply = app(LineScheduleBot::class)->reply('!bet help');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('指令格式：', $reply->text);
        $this->assertStringContainsString('!bet', $reply->text);
        $this->assertStringContainsString('Stake', $reply->text);
    }

    public function test_bet_command_generates_valid_image(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to test bet image rendering.');
        }

        $font = collect([
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ])->first(fn (string $path): bool => is_readable($path));

        if ($font === null) {
            $this->markTestSkipped('A readable font is required to verify image rendering.');
        }

        config([
            'services.line.schedule_image_disk' => 'test-disk',
            'services.line.schedule_image_font' => $font,
        ]);
        \Illuminate\Support\Facades\Storage::fake('test-disk');

        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($this->sampleActiveSportBetsResponse(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertTrue($reply->prefersImage());

        $url = app(\App\Services\LineScheduleImageService::class)->create($reply->imageData, $reply->linkUrl);
        $this->assertNotEmpty($url);

        $files = \Illuminate\Support\Facades\Storage::disk('test-disk')->allFiles('line-schedules');
        $originalPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/1440'));
        $previewPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/700'));

        $this->assertNotNull($originalPath);
        $this->assertNotNull($previewPath);

        $original = new \Imagick;
        $original->readImageBlob(\Illuminate\Support\Facades\Storage::disk('test-disk')->get($originalPath));
        $this->assertSame(1440, $original->getImageWidth());
        $this->assertGreaterThan(500, $original->getImageHeight());
        $original->clear();
    }

    public function test_bet_command_handles_suspended_cashout(): void
    {
        $betsResponse = $this->sampleActiveSportBetsResponse();
        $betsResponse['data']['user']['activeSportBets'][0]['cashoutDisabled'] = true;

        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($betsResponse, 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('・即時兌現：暫停兌現 ⏸️', $reply->text);
        $this->assertTrue($reply->imageData['bets'][0]['cashout_disabled']);
    }

    public function test_bet_command_displays_in_progress_period_scores(): void
    {
        $betsResponse = $this->sampleActiveSportBetsResponse();
        // Bet 1, leg 1 (T1 vs DK): add kills scoreboard
        $betsResponse['data']['user']['activeSportBets'][0]['outcomes'][0]['fixture']['eventStatus']['scoreboard'] = [
            '__typename' => 'SportFixtureEventScoreboard',
            'homeKills' => 14,
            'awayKills' => 7,
            'homeWonRounds' => null,
            'awayWonRounds' => null,
            'homeGoals' => null,
            'awayGoals' => null,
        ];
        // Bet 1, leg 2 (IG vs WE): add rounds scoreboard
        $betsResponse['data']['user']['activeSportBets'][0]['outcomes'][1]['fixture']['eventStatus']['scoreboard'] = [
            '__typename' => 'SportFixtureEventScoreboard',
            'homeKills' => null,
            'awayKills' => null,
            'homeWonRounds' => 12,
            'awayWonRounds' => 11,
            'homeGoals' => null,
            'awayGoals' => null,
        ];

        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($betsResponse, 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('賽況：滾球中（0-0，一號地圖 14-7）', $reply->text);
        $this->assertStringContainsString('賽況：滾球中（1-1，三號地圖 12-11）', $reply->text);
        $this->assertSame('滾球中（0-0，一號地圖 14-7）', $reply->imageData['bets'][0]['legs'][0]['match_status']);
        $this->assertSame('滾球中（1-1，三號地圖 12-11）', $reply->imageData['bets'][0]['legs'][1]['match_status']);
    }

    public function test_bet_command_handles_zero_active_bets_with_balance(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push(['data' => ['user' => ['activeSportBetCount' => 0]]], 200)
                ->push($this->sampleUserBalancesResponse(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('目前無進行中的 Stake 體育注單。', $reply->text);
        $this->assertStringContainsString('資金水位｜可用：0.0083 USDT', $reply->text);
        $this->assertStringContainsString('完整注單｜https://stake.com/zh/my-bets/sports', $reply->text);
    }

    public function test_bet_command_formats_balance_with_vault(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($this->sampleActiveSportBetsResponse(), 200)
                ->push($this->sampleUserBalancesResponse(150.5, 2000.0), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('資金水位｜可用：150.5 USDT（金庫：2,000 USDT / 總計：2,150.5 USDT）', $reply->text);
        $this->assertSame('150.5 USDT（金庫 2,000）', $reply->imageData['balance_formatted']);
    }

    public function test_bet_command_handles_balance_argument(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response($this->sampleUserBalancesResponse(), 200),
        ]);

        $aliases = ['!bet balance', '!bet 水位', '!bet 資金', '!bet 餘額', '!bet usdt', '!bet bal'];

        foreach ($aliases as $alias) {
            $reply = app(LineScheduleBot::class)->reply($alias);
            $this->assertNotNull($reply, "Failed asserting that alias {$alias} was handled.");
            $this->assertStringContainsString('Stake 體育投注｜即時資金水位', $reply->text);
            $this->assertStringContainsString('資金水位｜可用：0.0083 USDT', $reply->text);
            $this->assertStringContainsString('完整注單｜https://stake.com/zh/my-bets/sports', $reply->text);
        }

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://stake.com/_api/graphql'
                && $request->header('x-operation-name')[0] === 'UserBalances';
        });
        Http::assertNotSent(function ($request): bool {
            return $request->header('x-operation-name')[0] === 'ActiveBetCount_User';
        });
    }

    public function test_bet_command_handles_balance_api_failure(): void
    {
        Http::fake([
            'https://stake.com/_api/graphql' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet balance');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Stake 認證失敗', $reply->text);
    }

    public function test_bet_command_image_renders_both_staked_and_balance(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to test bet image rendering.');
        }

        $font = collect([
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ])->first(fn (string $path): bool => is_readable($path));

        if ($font === null) {
            $this->markTestSkipped('A readable font is required to verify image rendering.');
        }

        config([
            'services.line.schedule_image_disk' => 'test-disk',
            'services.line.schedule_image_font' => $font,
        ]);
        \Illuminate\Support\Facades\Storage::fake('test-disk');

        Http::fake([
            'https://stake.com/_api/graphql' => Http::sequence()
                ->push($this->sampleActiveBetCountResponse(), 200)
                ->push($this->sampleActiveSportBetsResponse(), 200)
                ->push($this->sampleUserBalancesResponse(100.0, 50.0), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!bet');
        $this->assertNotNull($reply);
        $this->assertTrue($reply->prefersImage());

        $url = app(\App\Services\LineScheduleImageService::class)->create($reply->imageData, $reply->linkUrl);
        $this->assertNotEmpty($url);

        $files = \Illuminate\Support\Facades\Storage::disk('test-disk')->allFiles('line-schedules');
        $originalPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/1440'));
        $this->assertNotNull($originalPath);

        $original = new \Imagick;
        $original->readImageBlob(\Illuminate\Support\Facades\Storage::disk('test-disk')->get($originalPath));
        $this->assertSame(1440, $original->getImageWidth());
        $this->assertGreaterThan(500, $original->getImageHeight());
        $original->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleActiveBetCountResponse(): array
    {
        return [
            'data' => [
                'user' => [
                    'activeSportBetCount' => 3,
                    'activeSwishBetCount' => 0,
                    'activeRacingBetCount' => 0,
                    'activeSportsbookXMultiBetCount' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleActiveSportBetsResponse(): array
    {
        return [
            'data' => [
                'user' => [
                    'id' => '2ca2dc66-5764-4ddc-9981-9c2c5575e8ad',
                    'activeSportBets' => [
                        [
                            '__typename' => 'SportBet',
                            'id' => '80e19be6-6797-48b3-ac17-fb5cb12e65c6',
                            'active' => true,
                            'status' => 'confirmed',
                            'customBet' => false,
                            'cashoutDisabled' => false,
                            'customPrices' => [],
                            'amount' => 71.8,
                            'activeAmount' => null,
                            'currency' => 'usdt',
                            'payout' => 0,
                            'potentialMultiplier' => 2.145,
                            'payoutMultiplier' => 0,
                            'cashoutMultiplier' => 1.287,
                            'cashouts' => [],
                            'createdAt' => 'Sun, 06 Sep 2026 06:25:15 GMT',
                            'bet' => [
                                '__typename' => 'Bet',
                                'iid' => 'sport:649557501',
                            ],
                            'user' => [
                                '__typename' => 'User',
                                'id' => '2ca2dc66-5764-4ddc-9981-9c2c5575e8ad',
                            ],
                            'promotionBet' => null,
                            'adjustments' => [],
                            'outcomes' => [
                                [
                                    '__typename' => 'SportBetOutcome',
                                    'fixture' => [
                                        '__typename' => 'SportFixture',
                                        'tournament' => [
                                            '__typename' => 'SportTournament',
                                            'category' => [
                                                '__typename' => 'SportCategory',
                                                'sport' => [
                                                    '__typename' => 'Sport',
                                                    'slug' => 'league-of-legends',
                                                    'id' => '0cc37b2e-6c06-424a-90bb-c2df60ed73d1',
                                                    'name' => '英雄联盟',
                                                ],
                                                'slug' => 'international-1',
                                                'id' => 'b94c7461-271a-4eb0-8572-39daf512ae31',
                                                'name' => 'International',
                                            ],
                                            'id' => '345269bc-43cd-4db7-aa80-4ae3584da735',
                                            'slug' => 'lck-2026-season-playoffs-t3',
                                            'name' => 'LCK 2026 Season Playoffs',
                                        ],
                                        'id' => 'f53d775e-0ad5-417b-a215-f60c0e35ab63',
                                        'status' => 'live',
                                        'name' => 'T1 Esports - Dplus KIA',
                                        'data' => [
                                            '__typename' => 'SportFixtureDataMatch',
                                            'competitors' => [
                                                ['name' => 'T1 Esports', 'abbreviation' => 'T1'],
                                                ['name' => 'Dplus KIA', 'abbreviation' => 'Dplus KIA'],
                                            ],
                                            'startTime' => 'Sun, 06 Sep 2026 08:00:00 GMT',
                                        ],
                                        'eventStatus' => [
                                            '__typename' => 'EsportFixtureEventStatus',
                                            'matchStatus' => '一号地图',
                                            'homeScore' => 0,
                                            'awayScore' => 0,
                                        ],
                                    ],
                                    'id' => '485b13ba-f459-40d5-96b0-9d277be59974',
                                    'odds' => 1.65,
                                    'status' => 'pending',
                                    'outcome' => [
                                        '__typename' => 'SportMarketOutcome',
                                        'name' => 'T1 Esports',
                                        'odds' => 1.7,
                                    ],
                                    'market' => [
                                        '__typename' => 'SportMarket',
                                        'name' => '比赛获胜者 - Two 路线',
                                    ],
                                ],
                                [
                                    '__typename' => 'SportBetOutcome',
                                    'fixture' => [
                                        '__typename' => 'SportFixture',
                                        'tournament' => [
                                            '__typename' => 'SportTournament',
                                            'category' => [
                                                '__typename' => 'SportCategory',
                                                'sport' => [
                                                    '__typename' => 'Sport',
                                                    'name' => '英雄联盟',
                                                ],
                                            ],
                                            'name' => 'LPL 2026 Grand Finals',
                                        ],
                                        'id' => 'e1cbd10b-2358-417a-b09a-0374f2800efb',
                                        'status' => 'live',
                                        'name' => 'Invictus Gaming - Team WE',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'Invictus Gaming'],
                                                ['name' => 'Team WE'],
                                            ],
                                            'startTime' => 'Sun, 06 Sep 2026 06:08:00 GMT',
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '三号地图',
                                            'homeScore' => 1,
                                            'awayScore' => 1,
                                        ],
                                    ],
                                    'id' => 'db695ed6-e84b-488d-b5f3-0bbf45e61ea2',
                                    'odds' => 1.3,
                                    'status' => 'won',
                                    'outcome' => [
                                        'name' => 'Over 3.5',
                                        'odds' => 0,
                                    ],
                                    'market' => [
                                        'name' => '比赛地图数',
                                    ],
                                ],
                            ],
                        ],
                        [
                            '__typename' => 'SportBet',
                            'id' => 'a9a62461-65d5-4f6d-96e2-f73a1aa851e0',
                            'amount' => 30,
                            'currency' => 'usdt',
                            'potentialMultiplier' => 1.6,
                            'cashoutMultiplier' => 0.99,
                            'createdAt' => 'Sun, 06 Sep 2026 05:59:01 GMT',
                            'bet' => ['iid' => 'sport:649549583'],
                            'outcomes' => [
                                [
                                    '__typename' => 'SportBetOutcome',
                                    'fixture' => [
                                        'tournament' => [
                                            'category' => ['sport' => ['name' => '英雄联盟']],
                                            'name' => 'LPL 2026 Grand Finals',
                                        ],
                                        'status' => 'live',
                                        'name' => 'Invictus Gaming - Team WE',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'Invictus Gaming'],
                                                ['name' => 'Team WE'],
                                            ],
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '三号地图',
                                            'homeScore' => 1,
                                            'awayScore' => 1,
                                        ],
                                    ],
                                    'odds' => 1.6,
                                    'status' => 'pending',
                                    'outcome' => ['name' => 'Invictus Gaming'],
                                    'market' => ['name' => '比赛获胜者 - Two 路线'],
                                ],
                            ],
                        ],
                        [
                            '__typename' => 'SportBet',
                            'id' => 'aba9f280-e458-4615-a85a-83e50e8a765d',
                            'amount' => 50,
                            'currency' => 'usdt',
                            'potentialMultiplier' => 5.05296,
                            'cashoutMultiplier' => 0.99,
                            'createdAt' => 'Sun, 06 Sep 2026 05:58:48 GMT',
                            'bet' => ['iid' => 'sport:649549533'],
                            'outcomes' => [
                                [
                                    'fixture' => [
                                        'tournament' => [
                                            'category' => ['sport' => ['name' => '英雄联盟']],
                                            'name' => 'LCK 2026 Season Playoffs',
                                        ],
                                        'status' => 'live',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'T1 Esports'],
                                                ['name' => 'Dplus KIA'],
                                            ],
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '一号地图',
                                            'homeScore' => 0,
                                            'awayScore' => 0,
                                        ],
                                    ],
                                    'odds' => 1.65,
                                    'status' => 'pending',
                                    'outcome' => ['name' => 'T1 Esports'],
                                    'market' => ['name' => '比赛获胜者 - Two 路线'],
                                ],
                                [
                                    'fixture' => [
                                        'tournament' => [
                                            'category' => ['sport' => ['name' => '无畏契约']],
                                            'name' => 'VCT 2026：太平洋赛区第二阶段',
                                        ],
                                        'status' => 'active',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'Nongshim RedForce'],
                                                ['name' => 'Global Esports'],
                                            ],
                                            'startTime' => 'Sun, 06 Sep 2026 08:00:00 GMT',
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '未开始',
                                        ],
                                    ],
                                    'odds' => 1.32,
                                    'status' => 'pending',
                                    'outcome' => ['name' => 'Over 3.5'],
                                    'market' => ['name' => '比赛地图数'],
                                ],
                                [
                                    'fixture' => [
                                        'tournament' => [
                                            'category' => ['sport' => ['name' => '英雄联盟']],
                                            'name' => 'LPL 2026 Grand Finals',
                                        ],
                                        'status' => 'active',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'Ninjas in Pyjamas'],
                                                ['name' => 'LGD Gaming'],
                                            ],
                                            'startTime' => 'Sun, 06 Sep 2026 10:00:00 GMT',
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '未开始',
                                        ],
                                    ],
                                    'odds' => 1.45,
                                    'status' => 'pending',
                                    'outcome' => ['name' => 'Ninjas in Pyjamas'],
                                    'market' => ['name' => '比赛获胜者 - Two 路线'],
                                ],
                                [
                                    'fixture' => [
                                        'tournament' => [
                                            'category' => ['sport' => ['name' => '英雄联盟']],
                                            'name' => 'LPL 2026 Grand Finals',
                                        ],
                                        'status' => 'live',
                                        'data' => [
                                            'competitors' => [
                                                ['name' => 'Invictus Gaming'],
                                                ['name' => 'Team WE'],
                                            ],
                                        ],
                                        'eventStatus' => [
                                            'matchStatus' => '三号地图',
                                            'homeScore' => 1,
                                            'awayScore' => 1,
                                        ],
                                    ],
                                    'odds' => 1.6,
                                    'status' => 'pending',
                                    'outcome' => ['name' => 'Invictus Gaming'],
                                    'market' => ['name' => '比赛获胜者 - Two 路线'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleUserBalancesResponse(float $available = 0.008342077713606955, float $vault = 6.325308277155273e-9): array
    {
        return [
            'data' => [
                'user' => [
                    'id' => '2ca2dc66-5764-4ddc-9981-9c2c5575e8ad',
                    'balances' => [
                        [
                            'available' => [
                                'amount' => 0,
                                'currency' => 'btc',
                                '__typename' => 'Balance',
                            ],
                            'vault' => [
                                'amount' => 0,
                                'currency' => 'btc',
                                '__typename' => 'Balance',
                            ],
                            '__typename' => 'UserBalance',
                        ],
                        [
                            'available' => [
                                'amount' => $available,
                                'currency' => 'usdt',
                                '__typename' => 'Balance',
                            ],
                            'vault' => [
                                'amount' => $vault,
                                'currency' => 'usdt',
                                '__typename' => 'Balance',
                            ],
                            '__typename' => 'UserBalance',
                        ],
                    ],
                    '__typename' => 'User',
                ],
            ],
        ];
    }
}
