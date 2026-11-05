<?php

namespace Tests\Feature;

use App\Services\Bo3HeadToHeadService;
use App\Services\Bo3OddsService;
use App\Services\Bo3ScheduleService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use App\Services\OddsApiService;
use App\Services\StakeFixtureCodes;
use App\Services\StakeOddsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StakeOddsServiceTest extends TestCase
{
    private const ID = 'eac97d74-e71f-439e-99b9-eabe702f65b9';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 12:00:00', 'Asia/Taipei'));
        config([
            'services.stake.sources_file' => null,
            'services.stake.api_url' => 'https://stake.com/_api/graphql',
            'services.stake.access_token' => 'test-token',
            'services.stake.proxy' => null,
            'services.stake.max_retries' => 0,
            'cache.stores.redis' => ['driver' => 'array'],
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_aliases_get_the_same_fixture_and_only_the_three_requested_market_groups(): void
    {
        $this->fake([$this->fixture()]);
        foreach (['!odds T1', '!odds KC', '！odds Karmine Corp', '!賠率 T1 Esports'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertTrue($reply->prefersImage());
            $this->assertSame(self::ID, $reply->imageData['fixture_id']);
            $this->assertSame('2026/11/07 06:00', $reply->imageData['start_time']);
            $this->assertSame(['獨贏', '讓分', '大小分'], array_column($reply->imageData['markets'], 'group_label'));
            $this->assertStringContainsString('T1 Esports (-1.5)｜1.55', $reply->text);
            $this->assertStringContainsString('Karmine Corp (1.5)｜2.40', $reply->text);
            $this->assertStringContainsString('大於 3.5｜1.45', $reply->text);
            $this->assertStringNotContainsString('選手擊殺', $reply->text);
        }
        Http::assertSentCount(8); // Every command fetches fresh fixtures and odds.
        Http::assertSent(fn (Request $r): bool => $r['operationName'] === 'StakeFixtureOdds'
            && str_contains($r['query'], 'groups(groups: ["winner", "handicap", "total"])'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'odds-api'));
    }

    #[DataProvider('dates')]
    public function test_date_formats_select_the_taiwan_start_date(string $date, bool $found): void
    {
        $this->fake([$this->fixture()]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC '.$date);
        $this->assertSame($found, $reply->prefersImage());
        if (! $found) {
            $this->assertStringContainsString('查無', $reply->text);
        }
    }

    public static function dates(): array
    {
        return [['1107', true], ['11/07', true], ['2026-11-07', true], ['2026/11/07', true],
            ['後天', true], ['11-07', true], ['11/06', false], ['明天', false], ['今天', false]];
    }

    #[DataProvider('invalidCommands')]
    public function test_invalid_input_does_not_make_requests(string $command): void
    {
        Http::fake();
        $reply = app(LineScheduleBot::class)->reply($command);
        $this->assertFalse($reply->prefersImage());
        $this->assertStringContainsString('LoL Stake 盤口', $reply->text);
        Http::assertNothingSent();
    }

    public static function invalidCommands(): array
    {
        return [['!odds'], ['!odds help'], ['!odds T1 11/31'], ['!odds T1 0230'],
            ['!odds T1 2026-02-29'], ['!odds T1 page=0'], ['!odds T1 page=abc'],
            ['!odds https://stake.com.evil.example/sports/league-of-legends/a/b/c'],
            ['!odds https://stake.com@evil.example/sports/league-of-legends/a/b/c'],
            ['!odds https://stake.com/zh/sports'], ['!odds https://127.0.0.1/internal']];
    }

    public function test_blg_uses_explicit_registry_even_when_stake_abbreviation_is_the_full_name(): void
    {
        $fixture = $this->fixture();
        $fixture['name'] = 'Dplus KIA - Bilibili Gaming';
        $fixture['data']['competitors'] = [['name' => 'Dplus KIA', 'abbreviation' => 'Dplus KIA'],
            ['name' => 'Bilibili Gaming', 'abbreviation' => 'Bilibili Gaming']];
        $this->fake([$fixture]);
        foreach (['BLG', 'bili', 'bilibili'] as $alias) {
            $reply = app(LineScheduleBot::class)->reply('!odds '.$alias);
            $this->assertSame($fixture['name'], $reply->imageData['fixture_name']);
        }
    }

    public function test_short_names_do_not_substring_match_academy_squads(): void
    {
        $blue = $this->fixture('00000000-0000-0000-0000-000000000001');
        $blue['name'] = 'Karmine Corp Blue - T1 Academy';
        $blue['data']['competitors'] = [['name' => 'Karmine Corp Blue', 'abbreviation' => 'KCB'],
            ['name' => 'T1 Academy', 'abbreviation' => 'T1A']];
        $this->fake([$blue, $this->fixture()]);
        $this->assertSame(self::ID, app(LineScheduleBot::class)->reply('!odds KC')->imageData['fixture_id']);
        $this->assertSame(self::ID, app(LineScheduleBot::class)->reply('!odds T1')->imageData['fixture_id']);
        $this->assertSame($blue['id'], app(LineScheduleBot::class)->reply('!odds KCB')->imageData['fixture_id']);
    }

    public function test_live_is_preferred_and_finished_or_stale_upcoming_fixtures_are_excluded(): void
    {
        $live = $this->fixture('00000000-0000-0000-0000-000000000002');
        $live['data']['startTime'] = '2026-11-05T03:00:00Z';
        $live['status'] = 'live';
        $stale = $this->fixture('00000000-0000-0000-0000-000000000003');
        $stale['data']['startTime'] = '2026-11-04T22:00:00Z';
        $this->fake([$stale, $this->fixture()], [$live]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC');
        $this->assertSame($live['id'], $reply->imageData['fixture_id']);
        $this->assertSame('滾球中', $reply->imageData['status']);
    }

    public function test_multiple_matches_on_a_date_offer_fixture_ids_instead_of_guessing(): void
    {
        $second = $this->fixture('00000000-0000-0000-0000-000000000004');
        $second['data']['startTime'] = '2026-11-07T03:00:00Z';
        $this->fake([$this->fixture(), $second]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC 11/07');
        $this->assertFalse($reply->prefersImage());
        $this->assertCount(2, $reply->quickReplies);
        $this->assertSame('!odds '.app(StakeFixtureCodes::class)->forFixture(self::ID), $reply->quickReplies[0]['text']);
        Http::assertSentCount(1);
        $this->assertSame(self::ID, app(LineScheduleBot::class)->reply($reply->quickReplies[0]['text'])->imageData['fixture_id']);
    }

    public function test_reused_abbreviations_offer_choices_even_without_a_date(): void
    {
        $other = $this->fixture('00000000-0000-0000-0000-000000000005');
        $other['data']['startTime'] = '2026-11-08T03:00:00Z';
        $other['data']['competitors'][0] = ['name' => 'Another Club', 'abbreviation' => 'KC'];
        $this->fake([$this->fixture(), $other]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC');
        $this->assertStringContainsString('找到多場', $reply->text);
        Http::assertSentCount(1);
    }

    public function test_fixture_url_resolves_by_slug_without_fetching_the_user_url(): void
    {
        $this->fake([$this->fixture()]);
        $url = 'https://stake.com/zh/sports/league-of-legends/international-1/world-championship-2026-t3/'.$this->fixture()['slug'];
        $reply = app(LineScheduleBot::class)->reply('!odds '.$url.' text');
        $this->assertFalse($reply->prefersImage());
        $this->assertSame($url, $reply->linkUrl);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['operationName'] === 'StakeFixtureOddsBySlug'
            && $r['variables']['value'] === $this->fixture()['slug']);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'GET');
    }

    public function test_suspended_or_inactive_outcomes_never_show_actionable_prices(): void
    {
        $fixture = $this->fixture();
        $fixture['groups'][0]['templates'][0]['markets'][0]['status'] = 'suspended';
        $fixture['groups'][1]['templates'][0]['markets'][0]['outcomes'][0]['active'] = false;
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply('!odds T1');
        $this->assertSame(['暫停', '暫停'], array_column($reply->imageData['markets'][0]['outcomes'], 'price'));
        $this->assertSame('暫停', $reply->imageData['markets'][1]['outcomes'][0]['price']);
        $this->assertStringNotContainsString('4.50', $reply->text);
    }

    public function test_missing_markets_are_explicit_and_do_not_use_another_bookmaker(): void
    {
        $fixture = $this->fixture();
        $fixture['groups'] = [];
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC');
        $this->assertStringContainsString('暫無盤口', $reply->text);
        $this->assertSame([], $reply->imageData['markets']);
        Http::assertSentCount(2);
    }

    public function test_source_failure_is_not_reported_as_no_match(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'unavailable']]])]);
        $reply = app(LineScheduleBot::class)->reply('!odds T1');
        $this->assertStringContainsString('無法取得完整', $reply->text);
        $this->assertStringNotContainsString('查無', $reply->text);
    }

    public function test_odds_share_the_existing_domain_order_and_cooldown(): void
    {
        config(['services.stake.sources_file' => 'config/stake_sources.json']);
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = $request->url();
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            if (count($seen) <= 2) {
                return Http::response('region blocked', 451);
            }

            return Http::response($request['operationName'] === 'StakeOddsFixtures'
                ? $this->listResponse([$this->fixture()]) : ['data' => ['sportFixture' => $this->fixture()]]);
        });
        $this->assertTrue(app(LineScheduleBot::class)->reply('!odds T1')->prefersImage());
        $this->assertSame(['https://stake.ac/_api/v1/graphql', 'https://stake.mba/_api/v1/graphql',
            'https://staketr.com/_api/v1/graphql', 'https://staketr.com/_api/v1/graphql'], $seen);
    }

    public function test_fixture_pagination_is_completed_before_selecting_a_match(): void
    {
        Http::fake(function (Request $request) {
            if ($request['operationName'] !== 'StakeOddsFixtures') {
                return Http::response(['data' => ['sportFixture' => $this->fixture()]]);
            }
            if ($request['variables']['offset'] === 0) {
                $unrelated = $this->fixture();
                $unrelated['data']['competitors'] = [['name' => 'Unrelated', 'abbreviation' => 'U']];
                $result = $this->listResponse(array_fill(0, 50, $unrelated));
            } else {
                $result = $this->listResponse([$this->fixture()]);
            }
            $result['data']['slugSport']['upcomingCount'] = 51;

            return Http::response($result);
        });
        $this->assertSame(self::ID, app(LineScheduleBot::class)->reply('!odds T1')->imageData['fixture_id']);
        Http::assertSentCount(3);
    }

    public function test_teams_command_lists_all_six_leagues_without_api_calls(): void
    {
        Http::fake();
        $text = app(LineScheduleBot::class)->respond('!odds teams');
        foreach (['LCK', 'LPL', 'LEC', 'LCS', 'CBLOL', 'LCP'] as $league) {
            $this->assertStringContainsString('【'.$league.'】', $text);
        }
        $this->assertStringContainsString('BLG｜BILIBILI GAMING', $text);
        $this->assertStringContainsString('KCB｜Karmine Corp Blue（Versus 客席）', $text);
        Http::assertNothingSent();
    }

    public function test_schedule_codes_match_both_opponents_and_time_and_resolve_to_live_odds(): void
    {
        $this->fake([$this->fixture()]);
        $match = ['game' => 'lol', 'team1' => 'T1', 'team2' => 'KC',
            'start_at' => CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Taipei')];
        $matches = app(StakeOddsService::class)->enrichSchedule([
            $match, [...$match, 'team2' => 'BLG'], [...$match, 'start_at' => $match['start_at']->addDay()],
            [...$match, 'game' => 'valorant'],
        ]);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{5}$/', $matches[0]['stake_code']);
        foreach (array_slice($matches, 1) as $unmatched) {
            $this->assertArrayNotHasKey('stake_code', $unmatched);
        }
        $reply = app(LineScheduleBot::class)->reply('!odds '.strtolower($matches[0]['stake_code']));
        $this->assertSame(self::ID, $reply->imageData['fixture_id']);
        Http::assertSentCount(2); // Short code skips the fixture-list lookup.
    }

    public function test_unknown_or_expired_code_does_not_fetch_a_different_fixture(): void
    {
        Http::fake();
        $this->assertStringContainsString('不存在或已過期', app(LineScheduleBot::class)->respond('!odds K7M2Q'));
        Http::assertNothingSent();
    }

    public function test_match_command_includes_a_working_code_in_text_and_image_data(): void
    {
        $this->fake([$this->fixture()]);
        $match = ['game' => 'lol', 'name' => 'Karmine Corp vs T1', 'team1' => 'Karmine Corp', 'team2' => 'T1',
            'start_at' => CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Taipei'),
            'format' => 'BO5', 'tournament' => 'Worlds 2026', 'url' => 'https://bo3.gg/lol/matches/test', 'odds' => null];
        $this->partialMock(Bo3ScheduleService::class, function ($mock) use ($match): void {
            $mock->shouldReceive('forRange')->once()->andReturn(['matches' => [$match], 'failed_requests' => []]);
            $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->andReturnUsing(fn (array $matches): array => $matches);
        });
        foreach ([Bo3HeadToHeadService::class => 'enrich', OddsApiService::class => 'enrich', Bo3OddsService::class => 'enrichMissing'] as $class => $method) {
            $this->mock($class, fn ($mock) => $mock->shouldReceive($method)->andReturnUsing(fn (array $matches): array => $matches));
        }
        $reply = app(LineScheduleBot::class)->reply('!match 1107 game=lol');
        $code = $reply->imageData['matches'][0]['stake_code'];
        $this->assertNotNull($code);
        $this->assertStringContainsString('遊戲編號｜'.$code, $reply->text);
        $this->assertSame(self::ID, app(LineScheduleBot::class)->reply('!odds '.$code)->imageData['fixture_id']);
    }

    public function test_odds_images_use_the_existing_line_storage_resolutions(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick required.');
        }
        config(['services.line.schedule_image_disk' => 'odds-images']);
        Storage::fake('odds-images');
        $this->fake([$this->fixture()]);
        $reply = app(LineScheduleBot::class)->reply('!odds KC');
        app(LineScheduleImageService::class)->create($reply->imageData, $reply->linkUrl);
        $files = Storage::disk('odds-images')->allFiles('line-schedules');
        $this->assertCount(2, $files);
        foreach ($files as $path) {
            $image = new Imagick;
            $image->readImageBlob(Storage::disk('odds-images')->get($path));
            $this->assertSame((int) basename($path), $image->getImageWidth());
            $this->assertGreaterThan(300, $image->getImageHeight());
            $this->assertSame('PNG', $image->getImageFormat());
            $image->clear();
        }
    }

    private function fake(array $upcoming, array $live = []): void
    {
        Http::fake(function (Request $request) use ($upcoming, $live) {
            if ($request['operationName'] === 'StakeOddsFixtures') {
                return Http::response($this->listResponse($upcoming, $live));
            }
            foreach ([...$upcoming, ...$live] as $fixture) {
                if (in_array($request['variables']['value'], [$fixture['id'], $fixture['slug']], true)) {
                    return Http::response(['data' => ['sportFixture' => $fixture]]);
                }
            }

            return Http::response(['data' => ['sportFixture' => null]]);
        });
    }

    private function listResponse(array $upcoming, array $live = []): array
    {
        return ['data' => ['slugSport' => ['upcomingCount' => count($upcoming), 'liveCount' => count($live),
            'upcoming' => $upcoming, 'live' => $live]]];
    }

    private function fixture(string $id = self::ID): array
    {
        $market = fn (string $name, array $outcomes): array => ['templates' => [['markets' => [[
            'id' => $name, 'name' => $name, 'status' => 'active', 'specifiers' => '',
            'outcomes' => array_map(fn (string $label, float $price): array => ['id' => $label, 'name' => $label, 'odds' => $price, 'active' => true], array_keys($outcomes), array_values($outcomes)),
        ]]]]];

        return [
            'id' => $id, 'name' => 'Karmine Corp - T1 Esports', 'status' => 'active',
            'slug' => '1793829600000-karmine-corp-t1-esports',
            'data' => ['startTime' => '2026-11-06T22:00:00Z', 'competitors' => [
                ['name' => 'Karmine Corp', 'abbreviation' => 'KC'], ['name' => 'T1 Esports', 'abbreviation' => 'T1'],
            ]],
            'tournament' => ['name' => 'World Championship 2026', 'slug' => 'world-championship-2026-t3',
                'category' => ['slug' => 'international-1', 'sport' => ['name' => '英雄联盟', 'slug' => 'league-of-legends']]],
            'groups' => [
                ['name' => 'winner', ...$market('比赛获胜者', ['Karmine Corp' => 4.5, 'T1 Esports' => 1.2])],
                ['name' => 'handicap', ...$market('比赛让分', ['Karmine Corp (1.5)' => 2.4, 'T1 Esports (-1.5)' => 1.55])],
                ['name' => 'total', ...$market('比赛地图数', ['Under 3.5' => 2.6, 'Over 3.5' => 1.45])],
                ['name' => 'main', ...$market('選手擊殺', ['Over 3.5' => 2.0])],
            ],
        ];
    }
}
