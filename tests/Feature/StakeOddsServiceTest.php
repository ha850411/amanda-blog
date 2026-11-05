<?php

namespace Tests\Feature;

use App\Services\Bo3HeadToHeadService;
use App\Services\Bo3OddsService;
use App\Services\Bo3ScheduleService;
use App\Services\LineSchedule\Support\StakeOddsCatalog;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use App\Services\MlbScheduleService;
use App\Services\NbaScheduleService;
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
            && str_contains($r['query'], 'groups(groups: ["winner", "handicap", "total", "Handicap", "Total"])'));
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
        $this->assertStringContainsString('Stake 盤口｜LoL／CS2／VAL／MLB／NBA', $reply->text);
        Http::assertNothingSent();
    }

    public static function invalidCommands(): array
    {
        return [['!odds T1 11/31'], ['!odds T1 0230'],
            ['!odds T1 2026-02-29'], ['!odds T1 page=0'], ['!odds T1 page=abc'],
            ['!odds G2 game=unknown'], ['!odds G2 game=lol/cs'], ['!odds G2 game=lol game=cs'],
            ['!odds https://stake.com/sports/basketball/usa/ncaa/test'],
            ['!odds https://stake.com/sports/baseball/japan/npb/test'],
            ['!odds https://stake.com/sports/basketball/usa/nba/test game=mlb'],
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
            $result['data']['lol']['upcomingCount'] = 51;

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

    public function test_g2_across_games_offers_labeled_choices_and_each_code_resolves_the_right_game(): void
    {
        $fixtures = [];
        foreach (['lol', 'cs', 'val'] as $index => $game) {
            $fixtures[] = $this->sportFixture($game, 'G2 Esports', 'G2', 'Team Liquid', 'TL', $index + 10);
        }
        $academy = $this->sportFixture('val', 'G2 Gozen', 'G2G', 'Team Liquid', 'TL', 13);
        $this->fake([...$fixtures, $academy]);
        $reply = app(LineScheduleBot::class)->reply('!odds G2');
        $this->assertFalse($reply->prefersImage());
        $this->assertCount(3, $reply->quickReplies);
        foreach (['LoL', 'CS2', 'VAL'] as $label) {
            $this->assertStringContainsString('【'.$label.'】', $reply->text);
        }
        $this->assertStringNotContainsString('G2 Gozen', $reply->text);
        Http::assertSentCount(1);
        foreach ($reply->quickReplies as $index => $quickReply) {
            $selected = app(LineScheduleBot::class)->reply($quickReply['text']);
            $this->assertSame($fixtures[$index]['id'], $selected->imageData['fixture_id']);
            $this->assertSame(['lol', 'cs', 'val'][$index], $selected->imageData['game']);
        }
        Http::assertSentCount(4);
    }

    #[DataProvider('gameCommands')]
    public function test_explicit_game_queries_only_that_sport(string $command, string $game): void
    {
        $fixture = $this->sportFixture($game, 'G2 Esports', 'G2', 'Team Liquid', 'TL', 15);
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply($command);
        $this->assertSame($game, $reply->imageData['game']);
        $this->assertStringContainsString('Stake｜'.StakeOddsCatalog::GAMES[$game]['label'], $reply->text);
        Http::assertSent(fn (Request $r): bool => $r['operationName'] === 'StakeOddsFixtures'
            && substr_count($r['query'], 'slugSport(') === 1
            && str_contains($r['query'], 'sport: "'.StakeOddsCatalog::GAMES[$game]['sport'].'"'));
    }

    public static function gameCommands(): array
    {
        return [['!odds G2 game=lol', 'lol'], ['!odds G2 game=cs', 'cs'],
            ['!odds G2 GAME=CS2', 'cs'], ['!odds game=val G2 11/07', 'val'],
            ['!odds G2 1107 game=valorant', 'val']];
    }

    #[DataProvider('sportsTeams')]
    public function test_sports_team_aliases_work_with_localized_provider_names(string $game, string $name, string $abbreviation, array $aliases): void
    {
        config(['nba.team_ids' => [], 'mlb.team_ids' => []]);
        $fixture = $this->sportFixture($game, $name, $abbreviation, 'Opponent', 'OPP', 16);
        $this->fake([$fixture]);
        foreach ($aliases as $alias) {
            $reply = app(LineScheduleBot::class)->reply('!odds '.$alias.' game='.$game);
            $this->assertSame($fixture['id'], $reply->imageData['fixture_id'], $alias);
        }
    }

    public static function sportsTeams(): array
    {
        return [
            ['nba', '金州勇士', '勇士', ['勇士', '金州勇士', 'Warriors', 'GSW', 'Golden State Warriors']],
            ['nba', '密尔沃基雄鹿', '雄鹿', ['公鹿', 'Bucks', 'MIL']],
            ['nba', '亚特兰大鹰', '鹰', ['老鷹', 'Hawks', 'ATL']],
            ['nba', '圣安东尼奥马刺', '马刺', ['馬刺', 'Spurs', 'SAS']],
            ['mlb', '洛杉矶道奇', '道奇', ['道奇', '洛杉磯道奇', 'Dodgers', 'LAD', 'Los Angeles Dodgers']],
            ['mlb', '亚特兰大勇士', '勇士', ['勇士', '亞特蘭大勇士', 'Braves', 'ATL']],
        ];
    }

    public function test_warriors_and_braves_are_separate_choices_even_if_one_is_live(): void
    {
        $nba = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 20);
        $mlb = $this->sportFixture('mlb', '亞特蘭大勇士', '勇士', '紐約洋基', '洋基', 21);
        $mlb['status'] = 'live';
        $mlb['data']['startTime'] = '2026-11-05T03:00:00Z';
        $this->fake([$nba], [$mlb]);
        $reply = app(LineScheduleBot::class)->reply('!odds 勇士 text');
        $this->assertCount(2, $reply->quickReplies);
        $this->assertStringContainsString('【NBA】', $reply->text);
        $this->assertStringContainsString('【MLB】', $reply->text);
        $this->assertStringContainsString('滾球中', $reply->text);
        $this->assertStringContainsString('text', $reply->quickReplies[0]['text']);
        $this->assertFalse(app(LineScheduleBot::class)->reply($reply->quickReplies[0]['text'])->prefersImage());
        $this->assertSame($nba['id'], app(LineScheduleBot::class)->reply('!odds 勇士 game=nba')->imageData['fixture_id']);
        $this->assertSame($mlb['id'], app(LineScheduleBot::class)->reply('!odds 勇士 game=mlb')->imageData['fixture_id']);
    }

    public function test_mlb_doubleheader_on_requested_date_offers_both_games(): void
    {
        $first = $this->sportFixture('mlb', 'Los Angeles Dodgers', 'LAD', 'Atlanta Braves', 'ATL', 22);
        $second = $this->sportFixture('mlb', 'Los Angeles Dodgers', 'LAD', 'Atlanta Braves', 'ATL', 23);
        $second['data']['startTime'] = '2026-11-07T03:00:00Z';
        $this->fake([$second, $first]);
        $reply = app(LineScheduleBot::class)->reply('!odds 道奇 1107 game=mlb');
        $this->assertCount(2, $reply->quickReplies);
        $this->assertStringContainsString('11/07 06:00', $reply->text);
        $this->assertStringContainsString('11/07 11:00', $reply->text);
        $this->assertSame($first['id'], app(LineScheduleBot::class)->reply('!odds 道奇 game=mlb')->imageData['fixture_id']);
    }

    public function test_nba_and_mlb_filter_other_leagues_and_validate_direct_fixture_lookups(): void
    {
        $nba = $this->sportFixture('nba', 'Golden State Warriors', 'GSW', 'Miami Heat', 'MIA', 24);
        $nba['tournament']['slug'] = 'nba-summer-league';
        $mlb = $this->sportFixture('mlb', 'Los Angeles Dodgers', 'LAD', 'Atlanta Braves', 'ATL', 25);
        $mlb['tournament']['category']['slug'] = 'japan';
        $this->fake([$nba, $mlb]);
        foreach (['!odds 勇士 game=nba', '!odds 道奇 game=mlb'] as $command) {
            $this->assertStringContainsString('查無', app(LineScheduleBot::class)->reply($command)->text);
        }
        foreach ([$nba, $mlb] as $fixture) {
            $reply = app(LineScheduleBot::class)->reply('!odds id='.$fixture['id']);
            $this->assertStringContainsString('不在支援範圍', $reply->text);
            $this->assertFalse($reply->prefersImage());
        }
    }

    public function test_sports_urls_and_codes_resolve_without_searching_and_reject_game_mismatch(): void
    {
        $nba = $this->sportFixture('nba', 'Golden State Warriors', 'GSW', 'Miami Heat', 'MIA', 26);
        $this->fake([$nba]);
        $url = 'https://stake.com/zh/sports/basketball/usa/nba/'.$nba['slug'];
        $reply = app(LineScheduleBot::class)->reply('!odds '.$url);
        $this->assertSame($url, $reply->linkUrl);
        $this->assertSame('nba', $reply->imageData['game']);
        $code = $reply->imageData['fixture_code'];
        $this->assertStringContainsString('不符', app(LineScheduleBot::class)->reply('!odds '.$code.' game=mlb')->text);
        Http::assertNotSent(fn (Request $r): bool => $r['operationName'] === 'StakeOddsFixtures' || $r->method() === 'GET');
    }

    public function test_capitalized_sports_market_groups_are_included_and_period_markets_are_excluded(): void
    {
        $fixture = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 27);
        $fixture['groups'][1]['name'] = 'Handicap';
        $fixture['groups'][2]['name'] = 'Total';
        $period = $fixture['groups'][1]['templates'][0]['markets'][0];
        $period['id'] = 'quarter-handicap';
        $period['name'] = '第一節讓分';
        $period['specifiers'] = 'quarternr=1|hcp=-2.5';
        $fixture['groups'][1]['templates'][0]['markets'][] = $period;
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply('!odds 勇士 game=nba');
        $this->assertSame(['獨贏', '讓分', '大小分'], array_column($reply->imageData['markets'], 'group_label'));
        $this->assertStringNotContainsString('第一節', $reply->text);
        $this->assertStringNotContainsString('選手擊殺', $reply->text);
    }

    public function test_incomplete_cross_game_response_does_not_silently_choose_a_match(): void
    {
        Http::fake(function () {
            $response = $this->listResponse([$this->fixture()]);
            $response['data']['cs'] = null;

            return Http::response($response);
        });
        $reply = app(LineScheduleBot::class)->reply('!odds KC');
        $this->assertStringContainsString('無法取得完整', $reply->text);
        $this->assertFalse($reply->prefersImage());
        Http::assertSentCount(1);
    }

    public function test_sports_team_lists_are_available_without_network_requests(): void
    {
        Http::fake();
        foreach (['nba' => '勇士｜Golden State Warriors', 'mlb' => '道奇｜Los Angeles Dodgers'] as $game => $expected) {
            $this->assertStringContainsString($expected, app(LineScheduleBot::class)->reply('!odds teams game='.$game)->text);
        }
        Http::assertNothingSent();
    }

    public function test_each_sport_finishes_pagination_before_cross_game_disambiguation(): void
    {
        $lol = $this->sportFixture('lol', 'G2 Esports', 'G2', 'Team Liquid', 'TL', 30);
        $cs = $this->sportFixture('cs', 'G2 Esports', 'G2', 'Team Liquid', 'TL', 31);
        $unrelated = $this->sportFixture('cs', 'Unrelated', 'U', 'Opponent', 'OPP', 32);
        Http::fake(function (Request $request) use ($lol, $cs, $unrelated) {
            if ($request['variables']['offset'] === 0) {
                $response = $this->listResponse([$lol, ...array_fill(0, 50, $unrelated)]);
            } else {
                $this->assertSame(1, substr_count($request['query'], 'slugSport('));
                $this->assertStringContainsString('cs: slugSport', $request['query']);
                $response = $this->listResponse([$cs]);
            }
            $response['data']['cs']['upcomingCount'] = 51;

            return Http::response($response);
        });
        $reply = app(LineScheduleBot::class)->reply('!odds G2');
        $this->assertCount(2, $reply->quickReplies);
        $this->assertStringContainsString('【LoL】', $reply->text);
        $this->assertStringContainsString('【CS2】', $reply->text);
        Http::assertSentCount(2);
    }

    public function test_mlb_markets_preserve_run_lines_totals_and_extra_innings_labels(): void
    {
        $fixture = $this->sportFixture('mlb', '洛杉磯道奇', '道奇', '亞特蘭大勇士', '勇士', 33);
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply('!odds 道奇 game=mlb');
        $this->assertSame('mlb', $reply->imageData['game']);
        $this->assertSame(['獨贏', '讓分', '大小分'], array_column($reply->imageData['markets'], 'group_label'));
        $this->assertStringContainsString('洛杉磯道奇 (-1.5)｜1.80', $reply->text);
        $this->assertStringContainsString('大於 8.5｜1.90', $reply->text);
        $this->assertStringContainsString('包括延長局', $reply->text);
        $this->assertStringNotContainsString('總局數', $reply->text);
    }

    public function test_pagination_keeps_the_selected_fixture_and_text_mode_without_a_refresh_button(): void
    {
        $fixture = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 34);
        $template = $fixture['groups'][2]['templates'][0]['markets'][0];
        for ($i = 1; $i <= 12; $i++) {
            $fixture['groups'][2]['templates'][0]['markets'][] = [...$template, 'id' => 'total-'.$i];
        }
        $this->fake([$fixture]);
        $reply = app(LineScheduleBot::class)->reply('!odds 勇士 game=nba text');
        $this->assertStringContainsString('第 1 / 2 頁', $reply->text);
        $next = array_values(array_filter($reply->quickReplies, fn (array $item): bool => $item['label'] === '下一頁'))[0];
        $second = app(LineScheduleBot::class)->reply($next['text']);
        $this->assertFalse($second->prefersImage());
        $this->assertStringContainsString('第 2 / 2 頁', $second->text);
        $this->assertSame($reply->linkUrl, $second->linkUrl);
        $this->assertStringEndsWith('page=1 text', $second->quickReplies[0]['text']);
        $this->assertNotContains('更新盤口', array_column($reply->quickReplies, 'label'));
        $this->assertNotContains('更新盤口', array_column($second->quickReplies, 'label'));
        Http::assertSentCount(3);
    }

    public function test_a_generated_code_that_is_also_a_team_name_uses_the_fixture_id(): void
    {
        $fixture = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 35);
        $second = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 36);
        $second['data']['startTime'] = '2026-11-07T03:00:00Z';
        $this->fake([$fixture, $second]);
        $this->mock(StakeFixtureCodes::class, fn ($mock) => $mock->shouldReceive('forFixture')->andReturn('HAWKS'));
        $reply = app(LineScheduleBot::class)->reply('!odds 勇士 game=nba');
        $this->assertNull($reply->imageData['fixture_code']);
        $this->assertSame([], $reply->quickReplies);
        $reply = app(LineScheduleBot::class)->reply('!odds 勇士 1107 game=nba');
        $this->assertStringStartsWith('!odds id='.$fixture['id'], $reply->quickReplies[0]['text']);
        $this->assertSame($fixture['id'], app(LineScheduleBot::class)->reply($reply->quickReplies[0]['text'])->imageData['fixture_id']);
    }

    public function test_removed_all_option_returns_guidance_without_fetching_markets(): void
    {
        Http::fake();
        foreach (['!odds T1 all', '!odds T1 1107 all', '!odds T1 game=lol 1107 ALL text', '!odds K7M2Q page=2 all'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertFalse($reply->prefersImage());
            $this->assertStringContainsString('不支援 all', $reply->text);
            $this->assertStringContainsString('整場獨贏、讓分與大小分', $reply->text);
        }
        Http::assertNothingSent();
    }

    public function test_odds_only_include_full_match_markets_and_exclude_map_and_player_markets(): void
    {
        $fixture = $this->fixture();
        $map = $fixture['groups'][0]['templates'][0]['markets'][0];
        $map['id'] = 'map-winner';
        $map['name'] = '地圖1獲勝者';
        $map['specifiers'] = 'map=1';
        $fixture['groups'][0]['templates'][0]['markets'][] = $map;
        $fixture['groups'][] = ['name' => 'maps', 'templates' => [['markets' => [$map]]]];
        $fixture['groups'][] = $fixture['groups'][0]; // Same market appears under multiple headings.
        $this->fake([$fixture]);
        foreach (['!odds T1 1107', '!odds T1', '!odds T1 game=lol 1107'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertSame(3, $reply->imageData['market_count']);
            $this->assertSame(['獨贏', '讓分', '大小分'], array_column($reply->imageData['markets'], 'group_label'));
            $this->assertStringNotContainsString('選手擊殺', $reply->text);
            $this->assertStringNotContainsString('地圖1獲勝者', $reply->text);
            $this->assertSame([], $reply->imageData['missing_groups']);
            $this->assertSame([], $reply->quickReplies);
        }
        Http::assertNotSent(fn (Request $r): bool => $r['operationName'] === 'StakeFixtureOdds'
            && ! str_contains($r['query'], 'groups(groups: ["winner", "handicap", "total", "Handicap", "Total"])'));
    }

    public function test_text_survives_fixture_selection_pagination_and_invalid_page_recovery(): void
    {
        $first = $this->fixture();
        $second = $this->fixture('00000000-0000-0000-0000-000000000040');
        $second['data']['startTime'] = '2026-11-07T03:00:00Z';
        $template = $first['groups'][2]['templates'][0]['markets'][0];
        for ($i = 0; $i < 12; $i++) {
            $first['groups'][2]['templates'][0]['markets'][] = [...$template, 'id' => 'total-'.$i];
        }
        $this->fake([$first, $second]);
        $choices = app(LineScheduleBot::class)->reply('!odds T1 1107 text');
        $this->assertCount(2, $choices->quickReplies);
        $this->assertStringEndsWith(' text', $choices->quickReplies[0]['text']);
        $page = app(LineScheduleBot::class)->reply($choices->quickReplies[0]['text']);
        $this->assertFalse($page->prefersImage());
        $this->assertStringContainsString('第 1 / 2 頁', $page->text);
        $next = $page->quickReplies[0];
        $this->assertSame('下一頁', $next['label']);
        $this->assertStringEndsWith('page=2 text', $next['text']);
        $last = app(LineScheduleBot::class)->reply($next['text']);
        $this->assertStringContainsString('第 2 / 2 頁', $last->text);
        $this->assertStringNotContainsString('選手擊殺', $last->text);
        $this->assertStringContainsString('大小分', $last->text);
        $this->assertStringNotContainsString('暫無盤口', $last->text);
        $recovery = app(LineScheduleBot::class)->reply(str_replace('page=2', 'page=99', $next['text']));
        $this->assertStringEndsWith(' text', $recovery->quickReplies[0]['text']);
        $this->assertStringContainsString('第 1 / 2 頁', app(LineScheduleBot::class)->reply($recovery->quickReplies[0]['text'])->text);
    }

    public function test_all_five_schedule_games_receive_codes_only_for_unique_team_time_and_game_matches(): void
    {
        $fixtures = [];
        $matches = [];
        foreach (['lol', 'cs', 'val', 'mlb', 'nba'] as $index => $game) {
            $team = match ($game) {
                'mlb' => '洛杉矶道奇', 'nba' => '金州勇士', default => 'G2 Esports'
            };
            $opponent = match ($game) {
                'mlb' => '亚特兰大勇士', 'nba' => '迈阿密热火', default => 'Team Liquid'
            };
            $fixtures[] = $this->sportFixture($game, $team, $team, $opponent, $opponent, 50 + $index);
            $matches[] = ['game' => $game === 'val' ? 'valorant' : $game,
                'team1' => match ($game) {
                    'mlb' => '道奇', 'nba' => '勇士', default => 'G2'
                },
                'team2' => match ($game) {
                    'mlb' => '亞特蘭大勇士', 'nba' => '邁阿密熱火', default => 'TL'
                },
                'start_at' => CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Taipei')];
        }
        $this->fake($fixtures);
        $result = app(StakeOddsService::class)->enrichSchedule([...$matches,
            [...$matches[0], 'start_at' => $matches[0]['start_at']->addHour()],
            [...$matches[1], 'team2' => 'Unknown'], [...$matches[3], 'is_finished' => true],
            [...$matches[4], 'time_tbd' => true], [...$matches[4], 'is_cancelled' => true],
        ]);
        foreach (array_slice($result, 0, 5) as $index => $match) {
            $this->assertArrayHasKey('stake_code', $match, 'Missing code for '.$match['game']);
            $this->assertNotEmpty($match['stake_code']);
            $reply = app(LineScheduleBot::class)->reply('!odds '.$match['stake_code']);
            $this->assertSame($fixtures[$index]['id'], $reply->imageData['fixture_id']);
        }
        foreach (array_slice($result, 5) as $match) {
            $this->assertArrayNotHasKey('stake_code', $match);
        }
        Http::assertSentCount(6); // One batched lookup, then five precise code lookups.
    }

    public function test_ambiguous_schedule_match_does_not_get_an_odds_code(): void
    {
        $fixture = $this->sportFixture('nba', '金州勇士', '勇士', '邁阿密熱火', '熱火', 59);
        $duplicate = [...$fixture, 'id' => '00000000-0000-0000-0000-000000000060'];
        $this->fake([$fixture, $duplicate]);
        $ambiguous = app(StakeOddsService::class)->enrichSchedule([[
            'game' => 'nba', 'team1' => '勇士', 'team2' => '熱火',
            'start_at' => CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Taipei'),
        ]]);
        $this->assertArrayNotHasKey('stake_code', $ambiguous[0]);
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

    #[DataProvider('scheduleGames')]
    public function test_match_command_includes_a_working_code_in_text_and_image_data(string $game, string $team, string $opponent): void
    {
        $fixture = $this->sportFixture($game, $team, $team, $opponent, $opponent, 70);
        $this->fake([$fixture]);
        $match = ['game' => $game === 'val' ? 'valorant' : $game, 'name' => $team.' vs '.$opponent, 'team1' => $team, 'team2' => $opponent,
            'start_at' => CarbonImmutable::parse('2026-11-07 06:00', 'Asia/Taipei'),
            'format' => 'BO5', 'tournament' => 'Test tournament', 'odds' => null];
        if (in_array($game, ['mlb', 'nba'], true)) {
            $this->partialMock($game === 'mlb' ? MlbScheduleService::class : NbaScheduleService::class, function ($mock) use ($match): void {
                $mock->shouldReceive('forRange')->once()->andReturn([$match]);
                $mock->shouldReceive('enrichRecentForm')->andReturnUsing(fn (array $matches): array => $matches);
            });
        } else {
            $this->partialMock(Bo3ScheduleService::class, function ($mock) use ($match): void {
                $mock->shouldReceive('forRange')->once()->andReturn(['matches' => [$match], 'failed_requests' => []]);
                $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->andReturnUsing(fn (array $matches): array => $matches);
            });
        }
        foreach ([Bo3HeadToHeadService::class => 'enrich', OddsApiService::class => 'enrich', Bo3OddsService::class => 'enrichMissing'] as $class => $method) {
            $this->mock($class, fn ($mock) => $mock->shouldReceive($method)->andReturnUsing(fn (array $matches): array => $matches));
        }
        $reply = app(LineScheduleBot::class)->reply('!match 1107 game='.$game);
        $code = $reply->imageData['matches'][0]['stake_code'];
        $this->assertNotNull($code);
        $this->assertStringContainsString('遊戲編號｜'.$code, $reply->text);
        $this->assertSame($fixture['id'], app(LineScheduleBot::class)->reply('!odds '.$code)->imageData['fixture_id']);
    }

    public static function scheduleGames(): array
    {
        return [['lol', 'T1', 'KC'], ['cs', 'G2', 'Team Liquid'], ['val', 'G2', 'Team Liquid'],
            ['mlb', '洛杉磯道奇', '亞特蘭大勇士'], ['nba', '金州勇士', '邁阿密熱火']];
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
        $data = [];
        foreach (StakeOddsCatalog::GAMES as $game => $definition) {
            $filter = fn (array $fixture): bool => ($fixture['tournament']['category']['sport']['slug'] ?? '') === $definition['sport'];
            $upcomingFixtures = array_values(array_filter($upcoming, $filter));
            $liveFixtures = array_values(array_filter($live, $filter));
            $data[$game] = ['upcomingCount' => count($upcomingFixtures), 'liveCount' => count($liveFixtures),
                'upcoming' => $upcomingFixtures, 'live' => $liveFixtures];
        }

        return ['data' => $data];
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

    private function sportFixture(string $game, string $team, string $short, string $opponent, string $opponentShort, int $id): array
    {
        $fixture = $this->fixture(sprintf('00000000-0000-0000-0000-%012d', $id));
        $fixture['name'] = $team.' - '.$opponent;
        $fixture['slug'] = 'test-'.$game.'-'.$id;
        $fixture['data']['competitors'] = [['name' => $team, 'abbreviation' => $short],
            ['name' => $opponent, 'abbreviation' => $opponentShort]];
        $definition = StakeOddsCatalog::GAMES[$game];
        $fixture['tournament'] = ['name' => $definition['label'], 'slug' => $definition['tournament'] ?? 'test-league',
            'category' => ['slug' => $definition['category'] ?? 'international', 'sport' => ['slug' => $definition['sport']]]];
        if (in_array($game, ['nba', 'mlb'], true)) {
            $settlement = $game === 'mlb' ? '（包括延長局）' : '（包含加時）';
            $total = $game === 'mlb' ? '8.5' : '235.5';
            foreach ([
                ['winner', '獲勝者', '', [$team => 1.6, $opponent => 2.4]],
                ['Handicap', '讓分', 'hcp=-1.5', [$team.' (-1.5)' => 1.8, $opponent.' (1.5)' => 2.0]],
                ['Total', '總得分', 'total='.$total, ['Over '.$total => 1.9, 'Under '.$total => 1.9]],
            ] as $index => [$group, $name, $specifiers, $outcomes]) {
                $fixture['groups'][$index] = ['name' => $group, 'templates' => [['markets' => [[
                    'id' => $group, 'name' => $name.$settlement, 'status' => 'active', 'specifiers' => $specifiers,
                    'outcomes' => array_map(fn (string $label, float $price): array => ['id' => $label,
                        'name' => $label, 'odds' => $price, 'active' => true], array_keys($outcomes), array_values($outcomes)),
                ]]]]];
            }
        }

        return $fixture;
    }
}
