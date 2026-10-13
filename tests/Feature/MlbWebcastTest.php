<?php

namespace Tests\Feature;

use App\Services\MlbWebcastService;
use App\Services\SportsHlsTransport;
use App\Services\SportsPlayerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MlbWebcastTest extends TestCase
{
    private const PAGE = 'https://mlbwebcast.com/milwaukee-brewers-live/';

    private const STREAM = 'https://mlbwebcast.com/stream/brewers.html';

    private const FINAL = 'https://s3.thegamehd.top/auth/130.m3u8?token=working';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 12, 21, 30, 0)->utc());
        config(['cache.default' => 'array', 'session.driver' => 'array', 'sports_player.manifest_cache_store' => 'array', 'mlbwebcast.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function match(): array
    {
        return ['id' => 'test-match', 'title' => 'Milwaukee Brewers vs Los Angeles Dodgers', 'translated_title' => '釀酒人 對 道奇',
            'league' => 'MLB', 'start_at' => '2026-10-12T21:00:00Z',
            'teams' => [['name' => 'Milwaukee Brewers'], ['name' => 'Los Angeles Dodgers']]];
    }

    private function catalog(string $date = 'October 12, 2026', string $time = '5:00', string $home = 'milwaukee-brewers'): string
    {
        return '<table><tbody id="tableinner"><tr><td class="matchtime">'.$time.'</td>'
            .'<td class="teamlogo"><a href="https://mlbwebcast.com/los-angeles-dodgers-live/">LA</a></td>'
            .'<td class="teamlogo"><a href="https://mlbwebcast.com/'.$home.'-live/">Home</a></td>'
            .'<td class="teamvs"><a href="'.self::PAGE.'">Dodgers @ Brewers <span class="mtdate">'.$date.'</span></a></td></tr></tbody></table>';
    }

    private function detail(): string
    {
        return '<iframe src="https://ads.example.test/player"></iframe><div id="multistmb">'
            .'<a href="'.self::STREAM.'">HOME</a><a href="https://mlbwebcast.com/stream/dodgers.html">AWAY</a>'
            .'<a href="https://mlbwebcast.com/stream/brewers2.html">LINK 3</a><a href="https://mlbwebcast.com/stream/brewers3.html">LINK 4</a>'
            .'<a href="https://127.0.0.1/stream/private.html">HOME</a></div>';
    }

    private function stream(): array
    {
        return ['url' => self::STREAM, 'platform' => 'MLBWebcast', 'provider' => 'MLBWebcast', 'language' => '主隊轉播', 'number' => 1,
            'resolver' => 'mlbwebcast', 'event_id' => 'test-match', 'event_expires_at' => '2026-10-13T03:00:00Z'];
    }

    private function fakeResolver(string $location = 'https://s3.thegamehd.top:443/auth/130.m3u8?token=working'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::STREAM => fn () => Http::response("<script>var _d=[130,'1234567890','0123456789abcdef'];</script>"),
            'https://mlbwebcast.com/stream/check_stream.php*' => fn () => Http::response(['url' => 'https://webcast-origin.b-cdn.net/bcdn_token=signature&token_path=%2Fplay%2Fchannel%2F&expires='.(now()->timestamp + 120).'/play/channel/130.m3u8']),
            'https://webcast-origin.b-cdn.net/*' => fn () => Http::response('', 302, ['Location' => $location]),
        ]);
    }

    public function test_matches_both_teams_eastern_date_and_time_and_caches_the_catalog(): void
    {
        Http::fake(['https://mlbwebcast.com/' => Http::response($this->catalog()), self::PAGE => Http::response($this->detail())]);
        $service = app(MlbWebcastService::class);
        foreach ([1, 2] as $viewer) {
            $result = $service->streams($this->match());
            $this->assertTrue($result['matched']);
            $this->assertCount(4, $result['data']);
            $this->assertSame('主隊轉播', $result['data'][0]['language']);
            $this->assertSame('客隊轉播', $result['data'][1]['language']);
            $players = app(SportsPlayerService::class);
            $this->assertNotNull($players->register($this->match(), $result['data'][0]));
            $this->assertNotNull($players->register($this->match(), $result['data'][1]));
            $this->assertNull($players->register($this->match(), $result['data'][2]));
            $this->assertNull($players->register($this->match(), $result['data'][3]));
        }
        Http::assertSentCount(2);
    }

    public function test_wrong_dates_doubleheaders_and_wrong_opponents_are_not_attached(): void
    {
        foreach ([$this->catalog('October 13, 2026'), $this->catalog('October 12, 2026', '8:00'), $this->catalog('October 12, 2026', '5:00', 'chicago-cubs')] as $html) {
            Cache::flush();
            Http::fake(['https://mlbwebcast.com/' => Http::response($html)]);
            $this->assertFalse(app(MlbWebcastService::class)->streams($this->match())['matched']);
            Http::assertSentCount(1);
        }
    }

    public function test_ambiguous_rows_and_untrusted_detail_links_do_not_fetch_players(): void
    {
        // Two real rows with the same identity must not be treated as one match.
        preg_match('~<tr>.*?</tr>~s', $this->catalog(), $row);
        $duplicate = '<table><tbody id="tableinner">'.$row[0].$row[0].'</tbody></table>';
        foreach ([$duplicate, str_replace(self::PAGE, 'https://127.0.0.1/private', $this->catalog())] as $html) {
            Cache::flush();
            Http::fake(['https://mlbwebcast.com/' => Http::response($html)]);
            $this->assertFalse(app(MlbWebcastService::class)->streams($this->match())['matched']);
            Http::assertSentCount(1);
        }
    }

    public function test_expired_team_channels_and_other_sports_do_not_fetch(): void
    {
        Http::fake();
        $match = $this->match();
        $match['league'] = 'NBA';
        $this->assertSame([], app(MlbWebcastService::class)->streams($match)['data']);
        $this->travel(7)->hours();
        $this->assertSame([], app(MlbWebcastService::class)->streams($this->match())['data']);
        Http::assertNothingSent();
    }

    public function test_player_registration_does_not_resolve_or_fetch_any_stream(): void
    {
        Http::fake();
        $token = app(SportsPlayerService::class)->register($this->match(), $this->stream());
        $this->get('/sports/player/'.$token)->assertOk()->assertSee('data-mode="hls"', false)
            ->assertSee('切換頁籤仍持續播放')->assertDontSee('<iframe', false);
        Http::assertNothingSent();
    }

    public function test_live_playlist_refreshes_signed_source_and_never_proxies_video(): void
    {
        $this->fakeResolver();
        $transport = Mockery::mock(SportsHlsTransport::class);
        $transport->shouldReceive('get')->twice()->with(self::FINAL, 262144)
            ->andReturn("#EXTM3U\n#EXTINF:4,\n/hls/130_100.ts?token=video\n");
        $this->app->instance(SportsHlsTransport::class, $transport);
        $token = app(SportsPlayerService::class)->register($this->match(), $this->stream());
        $path = '/sports/player/'.$token.'/hls/playlist';
        foreach ([1, 2] as $viewer) {
            $this->get($path)->assertOk()->assertSee('https://s3.thegamehd.top/hls/130_100.ts?token=video', false)
                ->assertDontSee('/hls/resource')->assertHeaderMissing('Set-Cookie');
        }
        Http::assertSentCount(3);
        $this->travel(46)->seconds();
        $this->get($path)->assertOk();
        Http::assertSentCount(6);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '.ts'));
    }

    public function test_unknown_redirect_hosts_ports_and_channels_are_rejected_before_fetch(): void
    {
        foreach (['https://127.0.0.1/auth/130.m3u8?token=x', 'https://s3.thegamehd.top.attacker.test/auth/130.m3u8?token=x',
            'https://s3.thegamehd.top:8443/auth/130.m3u8?token=x', 'http://s3.thegamehd.top/auth/130.m3u8?token=x',
            'https://s3.thegamehd.top/auth/131.m3u8?token=x'] as $url) {
            Cache::flush();
            $this->fakeResolver($url);
            $token = app(SportsPlayerService::class)->register($this->match(), $this->stream());
            $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
            Http::assertSentCount(3);
        }
    }

    public function test_source_failure_has_shared_backoff_and_does_not_fallback_to_media_proxy(): void
    {
        Http::fake([self::STREAM => Http::response('Unavailable', 429)]);
        $token = app(SportsPlayerService::class)->register($this->match(), $this->stream());
        foreach ([1, 2] as $viewer) {
            $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
        }
        Http::assertSentCount(1);
    }
}
