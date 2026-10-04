<?php

namespace Tests\Feature;

use App\Services\SportsScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'streameast.enabled' => false]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T08:00:00+08:00'));
    }

    public function test_standalone_schedule_translates_teams_and_links_badges_without_fetching_players(): void
    {
        Http::fake(['*/matches/all' => Http::response([$this->game()])]);
        $this->get('/sports')->assertOk()
            ->assertSee('密爾瓦基釀酒人')->assertSee('聖地牙哥教士')
            ->assertSee('Milwaukee Brewers')->assertSee('08:30')
            ->assertSee('MLB')->assertSee('/images/badge/home-badge.webp')
            ->assertSee('/sports/events/brewers-padres')
            ->assertDontSee('<iframe', false)->assertDontSee('adsbygoogle')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        Http::assertSentCount(1);
    }

    public function test_filters_support_chinese_search_leagues_dates_and_start_time(): void
    {
        $mlb = $this->game();
        $nba = $this->game('raptors-heat', 'basketball', 'Toronto Raptors', 'Miami Heat', '2026-10-03T16:30:00Z');
        $nbl = $this->game('nbl', 'basketball', 'Adelaide 36ers', 'New Zealand Breakers');
        Http::fake(['*/matches/all' => Http::response([$mlb, $nba, $nbl])]);
        $this->get('/sports?league=NBA&date=2026-10-04&status=started&q='.urlencode('暴龍'))
            ->assertOk()->assertSee('多倫多暴龍')->assertSee('00:30')
            ->assertDontSee('密爾瓦基釀酒人')->assertDontSee('阿德雷德三十六人');
        $this->get('/sports?sport=baseball&status=upcoming')->assertSee('密爾瓦基釀酒人')->assertDontSee('多倫多暴龍');
        $this->get('/sports?q=does-not-exist')->assertSee('沒有符合條件的賽程');
        Http::assertSentCount(1);
    }

    public function test_default_schedule_starts_at_taipei_midnight_and_counts_only_that_range(): void
    {
        Http::fake(['*/matches/all' => Http::response([
            $this->game('tomorrow', start: '2026-10-04T16:00:00Z'),
            $this->game('old', start: '2016-12-02T04:00:00Z'),
            $this->game('before-midnight', start: '2026-10-03T15:59:59Z'),
            $this->game('at-midnight', start: '2026-10-03T16:00:00Z'),
            $this->game('later-today'),
        ])]);

        $this->get('/sports')->assertOk()
            ->assertSeeInOrder(['/sports/events/at-midnight', '/sports/events/later-today', '/sports/events/tomorrow'])
            ->assertDontSee('/sports/events/old')->assertDontSee('/sports/events/before-midnight')
            ->assertSee('今天 · 星期日')
            ->assertViewHas('count', 3)->assertViewHas('total', 3)
            ->assertViewHas('leagueCounts', ['MLB' => 3, 'NBA' => 0])
            ->assertViewHas('rangeCounts', ['current' => 3, 'history' => 2])
            ->assertViewHas('categories', fn ($categories) => $categories['baseball']['count'] === 3);
    }

    public function test_history_lists_recent_days_first_and_displays_years_for_old_events(): void
    {
        Http::fake(['*/matches/all' => Http::response([
            $this->game('old', start: '2016-12-02T04:00:00Z'),
            $this->game('yesterday-late', start: '2026-10-03T12:00:00Z'),
            $this->game('today'),
            $this->game('yesterday-early', start: '2026-10-03T00:00:00Z'),
        ])]);

        $this->get('/sports?range=history')->assertOk()
            ->assertSeeInOrder(['/sports/events/yesterday-early', '/sports/events/yesterday-late', '/sports/events/old'])
            ->assertSee('2016 / 12 / 02')->assertDontSee('/sports/events/today')
            ->assertViewHas('count', 3)->assertViewHas('total', 3)
            ->assertSee('name="range" value="history"', false)
            ->assertSee(route('sports.index', ['range' => 'history', 'league' => 'MLB']));
    }

    public function test_explicit_dates_override_range_and_keep_other_filters(): void
    {
        Http::fake(['*/matches/all' => Http::response([
            $this->game('yesterday-mlb', start: '2026-10-02T16:00:00Z'),
            $this->game('yesterday-nba', 'basketball', 'Toronto Raptors', 'Miami Heat', '2026-10-02T17:00:00Z'),
            $this->game('today'),
        ])]);

        $this->get('/sports?date=2026-10-03&league=MLB&status=started&q='.urlencode('釀酒人'))
            ->assertOk()->assertSee('/sports/events/yesterday-mlb')
            ->assertDontSee('/sports/events/yesterday-nba')->assertDontSee('/sports/events/today')
            ->assertSee('目前顯示 2026-10-03 的台灣賽程')
            ->assertViewHas('count', 1)->assertViewHas('total', 2);
        $this->get('/sports?range=history&date=2026-10-04')
            ->assertOk()->assertSee('/sports/events/today')->assertDontSee('/sports/events/yesterday-mlb');
        $this->get('/sports?range=history&league=NBA')
            ->assertOk()->assertSee('/sports/events/yesterday-nba')->assertDontSee('/sports/events/yesterday-mlb');
    }

    public function test_range_rolls_over_at_taipei_midnight_without_refetching_cached_feed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04T23:59:59+08:00'));
        Http::fake(['*/matches/all' => Http::response([
            $this->game('ending-day'),
            $this->game('next-day', start: '2026-10-04T16:00:00Z'),
        ])]);

        $this->get('/sports')->assertOk()->assertSee('/sports/events/ending-day')->assertSee('/sports/events/next-day');
        $this->travel(2)->seconds();
        $this->get('/sports')->assertOk()->assertDontSee('/sports/events/ending-day')->assertSee('/sports/events/next-day');
        $this->get('/sports?range=history')->assertOk()->assertSee('/sports/events/ending-day')->assertDontSee('/sports/events/next-day');
        Http::assertSentCount(1);
    }

    public function test_future_year_is_visible_and_old_upstream_timestamp_is_preserved(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31T08:00:00+08:00'));
        $old = $this->game('lfc-63');
        $old['date'] = 1480651200000;
        Http::fake(['*/matches/all' => Http::response([
            $this->game('new-year', start: '2026-12-31T16:00:00Z'),
            $old,
            $this->game('year-end', start: '2026-12-31T12:00:00Z'),
        ])]);

        $this->get('/sports')->assertOk()->assertSeeInOrder(['12 / 31', '2027 / 01 / 01'])
            ->assertDontSee('/sports/events/lfc-63');
        $data = collect(app(SportsScheduleService::class)->schedule()['data']);
        $this->assertSame('2016-12-02T12:00:00+08:00', $data->firstWhere('id', 'lfc-63')['start_at']);
    }

    public function test_all_configured_mlb_and_nba_teams_have_chinese_labels(): void
    {
        foreach (config('sports.league_teams') as $teams) {
            foreach ($teams as $team) {
                $this->assertArrayHasKey($team, config('sports_labels.teams'));
                $this->assertMatchesRegularExpression('/\p{Han}/u', config('sports_labels.teams')[$team]);
                $this->assertArrayHasKey(strtolower($team), config('sports_badges'));
            }
        }
    }

    public function test_unknown_sports_and_missing_logos_preserve_names_and_fallback_initials(): void
    {
        $game = $this->game('unknown', 'volleyball', 'Alpha Team', 'Beta Team');
        unset($game['teams']['home']['badge'], $game['teams']['away']['badge']);
        Http::fake(['*/matches/all' => Http::response([$game])]);
        $this->get('/sports?sport=volleyball')->assertOk()->assertSee('Alpha Team')
            ->assertSee('volleyball')->assertSee('badge-fallback')->assertDontSee('class="team-badge"', false);
    }

    public function test_title_only_games_support_dash_and_vs_without_misclassifying_non_nba_teams(): void
    {
        $game = $this->game('lakers-kings', 'basketball');
        unset($game['teams']);
        $game['title'] = 'Los Angeles Lakers - Sacramento Kings';
        Http::fake(['*/matches/all' => Http::response([$game])]);
        $this->get('/sports?league=NBA')->assertOk()->assertSee('洛杉磯湖人')->assertSee('沙加緬度國王')
            ->assertSee('https://a.espncdn.com/i/teamlogos/nba/500/lal.png');
    }

    public function test_excludes_permanent_channels_invalid_rows_and_duplicates_but_keeps_doubleheaders(): void
    {
        $game = $this->game();
        $channel = array_replace($game, ['id' => 'channel', 'title' => 'Permanent Channel', 'date' => 0]);
        Http::fake(['*/matches/all' => Http::response([$game, $game, $this->game('second-game'), $channel, ['oops' => true]])]);
        $data = app(SportsScheduleService::class)->schedule()['data'];
        $this->assertCount(2, $data);
        $this->assertSame(['brewers-padres', 'second-game'], array_column($data, 'id'));
    }

    public function test_streams_are_loaded_on_demand_deduplicated_and_survive_partial_failure(): void
    {
        $game = $this->game();
        $game['sources'][] = ['source' => 'delta', 'id' => 'backup'];
        $stream = $this->stream();
        Http::fake([
            '*/matches/all' => Http::response([$game]),
            '*/stream/admin/main' => Http::response([$stream, $stream]),
            '*/stream/delta/backup' => Http::response([], 503),
        ]);
        $this->get('/sports/events/brewers-padres')->assertOk()
            ->assertSee($stream['embedUrl'])->assertSee('English')->assertSee('HD')
            ->assertSee('暫時無法取得 delta')->assertDontSee('<iframe', false);
        $result = app(SportsScheduleService::class)->streams(app(SportsScheduleService::class)->schedule()['data'][0]);
        $this->assertCount(1, $result['data']);
        $this->assertSame(['delta'], $result['failed_sources']);
        Http::assertSentCount(3);
    }

    public function test_unverified_streams_keep_original_links_without_in_site_player_links(): void
    {
        config(['sports_player.verified_embed_urls' => []]);
        $stream = $this->stream();
        Http::fake([
            '*/matches/all' => Http::response([$this->game()]),
            '*/stream/admin/main' => Http::response([$stream]),
        ]);

        $this->get('/sports/events/brewers-padres')->assertOk()
            ->assertSee($stream['embedUrl'])->assertSee('原連結')
            ->assertDontSee('/sports/player/')
            ->assertDontSee('每個來源均可選擇原連結，或另開站內播放器直接播放')
            ->assertViewHas('streams', fn ($streams) => $streams['data'][0]['player_url'] === null);
        Http::assertSentCount(2);
    }

    public function test_only_verified_stream_gets_a_player_link_in_a_mixed_source_list(): void
    {
        $supported = $this->stream();
        $unknown = array_replace($supported, ['embedUrl' => 'https://embed.st/embed/admin/main/2', 'streamNo' => 2]);
        config(['sports_player.verified_embed_urls' => [$supported['embedUrl']]]);
        Http::fake([
            '*/matches/all' => Http::response([$this->game()]),
            '*/stream/admin/main' => Http::response([$supported, $unknown]),
        ]);

        $response = $this->get('/sports/events/brewers-padres')->assertOk()
            ->assertSee($supported['embedUrl'])->assertSee($unknown['embedUrl'])
            ->assertViewHas('streams', fn ($streams) => $streams['data'][0]['player_url'] !== null
                && $streams['data'][1]['player_url'] === null);
        $this->assertSame(1, substr_count($response->getContent(), '/sports/player/'));
        $url = $response->viewData('streams')['data'][0]['player_url'];
        $this->get($url)->assertOk()->assertSee('data-embed-url="'.$supported['embedUrl'].'"', false);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_does_not_hide_other_providers(): void
    {
        $game = $this->game();
        $game['sources'][] = ['source' => 'delta', 'id' => 'backup'];
        Http::fake([
            '*/matches/all' => Http::response([$game]),
            '*/stream/admin/main' => Http::response([$this->stream()]),
            '*/stream/delta/backup' => Http::failedConnection(),
        ]);
        $this->get('/sports/events/brewers-padres')->assertOk()->assertSee('https://embed.st/embed/admin/main/1')->assertSee('暫時無法取得 delta');
    }

    public function test_rejects_unsafe_stream_urls_and_escapes_remote_text(): void
    {
        $game = $this->game();
        $game['teams']['home']['name'] = '<script>alert(1)</script>';
        $game['sources'][] = ['source' => '..', 'id' => '../../metadata'];
        Http::fake([
            '*/matches/all' => Http::response([$game]),
            '*/stream/admin/main' => Http::response([
                array_replace($this->stream(), ['embedUrl' => 'javascript:alert(1)']),
                array_replace($this->stream(), ['embedUrl' => 'https://embed.st.attacker.test/player']),
                array_replace($this->stream(), ['embedUrl' => 'https://user:pass@embed.st/player']),
                array_replace($this->stream(), ['embedUrl' => 'http://127.0.0.1/private']),
            ]),
        ]);
        $this->get('/sports/events/brewers-padres')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)
            ->assertDontSee('javascript:alert')->assertDontSee('attacker.test')->assertDontSee('user:pass')
            ->assertSee('目前無法取得播放器連結');
        Http::assertSentCount(2);
    }

    public function test_last_good_cache_is_marked_stale_on_error_and_expires(): void
    {
        Http::fake(['*/matches/all' => Http::sequence()->push([$this->game()])->pushStatus(503)->pushStatus(503)]);
        $this->get('/sports')->assertOk()->assertSee('密爾瓦基釀酒人');
        $this->travel(61)->seconds();
        $this->get('/sports')->assertOk()->assertSee('最近一次取得的賽程')->assertSee('密爾瓦基釀酒人');
        $this->get('/sports')->assertOk();
        Http::assertSentCount(2);
        $this->travel(901)->seconds();
        $this->get('/sports')->assertStatus(503)->assertSee('目前無法取得賽程')->assertDontSee('密爾瓦基釀酒人');
    }

    public function test_malformed_responses_do_not_replace_last_good_data(): void
    {
        Http::fake(['*/matches/all' => Http::sequence()->push([$this->game()])->push(['error' => 'unexpected'])->push([['schema' => 'changed']])]);
        $this->get('/sports')->assertOk();
        $this->travel(61)->seconds();
        $this->get('/sports')->assertOk()->assertSee('最近一次取得的賽程');
        $this->travel(61)->seconds();
        $this->get('/sports')->assertOk()->assertSee('密爾瓦基釀酒人')->assertSee('最近一次取得的賽程');
    }

    public function test_initial_failure_and_valid_empty_feed_are_different(): void
    {
        Http::fake(['*/matches/all' => Http::sequence()->pushStatus(503)->push([])]);
        $this->get('/sports')->assertStatus(503)->assertSee('目前無法取得賽程');
        $this->travel(16)->seconds();
        $this->get('/sports')->assertOk()->assertSee('沒有符合條件的賽程')->assertDontSee('目前無法取得賽程');
    }

    public function test_unknown_event_never_fetches_arbitrary_source_paths(): void
    {
        Http::fake(['*/matches/all' => Http::response([$this->game()])]);
        $this->get('/sports/events/unknown')->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_filter_validation_rejects_arrays_and_invalid_dates(): void
    {
        $this->getJson('/sports?q[]=x')->assertUnprocessable();
        $this->getJson('/sports?date=2026-02-31')->assertUnprocessable();
        $this->getJson('/sports?league=FAKE')->assertUnprocessable();
        $this->getJson('/sports?range=invalid')->assertUnprocessable();
        $this->getJson('/sports?range[]=history')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_empty_sources_show_pending_and_do_not_make_stream_requests(): void
    {
        $game = $this->game();
        $game['sources'] = [];
        Http::fake(['*/matches/all' => Http::response([$game])]);
        $this->get('/sports/events/brewers-padres')->assertOk()->assertSee('目前尚未提供播放器連結');
        Http::assertSentCount(1);
    }

    private function game(string $id = 'brewers-padres', string $category = 'baseball', string $home = 'Milwaukee Brewers', string $away = 'San Diego Padres', string $start = '2026-10-04T00:30:00Z'): array
    {
        return [
            'id' => $id, 'category' => $category, 'title' => $home.' vs '.$away,
            'date' => CarbonImmutable::parse($start)->getTimestampMs(),
            'teams' => ['home' => ['name' => $home, 'badge' => 'home-badge'], 'away' => ['name' => $away, 'badge' => 'away-badge']],
            'sources' => [['source' => 'admin', 'id' => 'main']],
        ];
    }

    private function stream(): array
    {
        return ['embedUrl' => 'https://embed.st/embed/admin/main/1', 'language' => 'English', 'hd' => true, 'streamNo' => 1];
    }
}
