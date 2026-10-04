<?php

namespace Tests\Feature;

use App\Services\StreameastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreameastTest extends TestCase
{
    private const EVENT = '/mlb/san-diego-padres-milwaukee-brewers/49170464';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'streameast.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T08:00:00+08:00'));
    }

    public function test_matches_reversed_teams_and_extracts_both_servers_without_chat_or_duplicate_default(): void
    {
        Http::fake([
            '*/mlbstreams3' => Http::response($this->catalog()),
            '*'.self::EVENT => Http::response($this->event()),
        ]);
        $result = app(StreameastService::class)->streams($this->match());
        $this->assertTrue($result['matched']);
        $this->assertFalse($result['unavailable']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('Server1', $result['data'][0]['language']);
        $this->assertSame('https://gooz.aapmains.net/new-stream-embed/57543?3newa=22', $result['data'][0]['url']);
        $this->assertSame('https://gooz.aapmains.net/new-stream-embed/57552?3newa=22', $result['data'][1]['url']);
        $this->assertNull($result['data'][0]['hd']);
        $this->assertSame('https://streameast.cool'.self::EVENT, $result['source_url']);
        app(StreameastService::class)->streams($this->match());
        Http::assertSentCount(2);
    }

    public function test_rejects_next_day_rematch_and_same_day_doubleheader(): void
    {
        Http::fake([
            '*/mlbstreams3' => Http::response($this->catalog()),
            '*'.self::EVENT => Http::response($this->event()),
        ]);
        foreach (['2026-10-05T00:30:00Z', '2026-10-04T04:30:00Z'] as $start) {
            $match = array_replace($this->match(), ['start_at' => $start]);
            $result = app(StreameastService::class)->streams($match);
            $this->assertFalse($result['matched']);
            $this->assertSame([], $result['data']);
        }
    }

    public function test_requires_detail_identity_even_if_catalog_title_matches(): void
    {
        Http::fake([
            '*/mlbstreams3' => Http::response($this->catalog()),
            '*'.self::EVENT => Http::response(str_replace('Milwaukee Brewers vs San Diego Padres', 'Los Angeles Dodgers vs San Diego Padres', $this->event())),
        ]);
        $this->assertFalse(app(StreameastService::class)->streams($this->match())['matched']);
    }

    public function test_catalog_cannot_cause_requests_to_other_hosts_or_local_paths(): void
    {
        $catalog = $this->catalog();
        $catalog = str_replace('https://streameast.cool'.self::EVENT, 'https://attacker.test'.self::EVENT, $catalog);
        Http::fake(['*/mlbstreams3' => Http::response($catalog)]);
        $this->assertSame([], app(StreameastService::class)->streams($this->match())['data']);
        Http::assertSentCount(1);
    }

    public function test_external_players_are_allowlisted_and_missing_script_keeps_default_iframe(): void
    {
        $html = preg_replace('~<script>.*?</script>~s', '', $this->event());
        Http::fake([
            '*/mlbstreams3' => Http::response($this->catalog()),
            '*'.self::EVENT => Http::sequence()->push($html)->push(str_replace('gooz.aapmains.net', 'attacker.test', $this->event())),
        ]);
        $this->assertCount(1, app(StreameastService::class)->streams($this->match())['data']);
        $this->travel(121)->seconds();
        $result = app(StreameastService::class)->streams($this->match());
        $this->assertTrue($result['matched']);
        $this->assertSame([], $result['data']);
    }

    public function test_stale_sources_expire_and_failures_back_off(): void
    {
        Http::fake([
            '*/mlbstreams3' => Http::sequence()->push($this->catalog())->pushStatus(503)->pushStatus(503),
            '*'.self::EVENT => Http::sequence()->push($this->event())->pushStatus(503),
        ]);
        $this->assertTrue(app(StreameastService::class)->streams($this->match())['matched']);
        $this->travel(121)->seconds();
        $result = app(StreameastService::class)->streams($this->match());
        $this->assertTrue($result['stale']);
        $this->assertCount(2, $result['data']);
        app(StreameastService::class)->streams($this->match());
        Http::assertSentCount(4);
        $this->travel(901)->seconds();
        $result = app(StreameastService::class)->streams($this->match());
        $this->assertTrue($result['unavailable']);
        $this->assertSame([], $result['data']);
    }

    public function test_challenge_pages_and_invalid_schema_fail_without_executing_scripts(): void
    {
        Http::fake(['*/mlbstreams3' => Http::response('<html>Just a moment</html>')]);
        $result = app(StreameastService::class)->streams($this->match());
        $this->assertTrue($result['unavailable']);
        $this->assertSame([], $result['data']);
    }

    public function test_unavailable_streameast_does_not_remove_sportsurge_streams(): void
    {
        Http::fake([
            '*/matches/all' => Http::response([[
                'id' => 'brewers-padres', 'title' => 'Milwaukee Brewers vs San Diego Padres',
                'category' => 'baseball', 'date' => 1791073800000,
                'sources' => [['source' => 'admin', 'id' => 'main']],
            ]]),
            '*/stream/admin/main' => Http::response([['embedUrl' => 'https://embed.st/embed/admin/main/1', 'language' => 'English', 'hd' => true]]),
            '*/mlbstreams3' => Http::failedConnection(),
        ]);
        $this->get('/sports/events/brewers-padres')->assertOk()->assertSee('https://embed.st/embed/admin/main/1')
            ->assertSee('Streameast 暫時無法更新');
    }

    public function test_unsupported_sports_never_fetch_catalogs(): void
    {
        $match = array_replace($this->match(), ['category' => 'tennis', 'league' => null]);
        $this->assertSame([], app(StreameastService::class)->streams($match)['data']);
        Http::assertNothingSent();
    }

    private function match(): array
    {
        return [
            'title' => 'Milwaukee Brewers vs San Diego Padres', 'category' => 'baseball', 'league' => 'MLB',
            'start_at' => '2026-10-04T00:30:00Z',
            'teams' => [['name' => 'Milwaukee Brewers'], ['name' => 'San Diego Padres']],
        ];
    }

    private function catalog(): string
    {
        return '<ul id="GelecekMaclar"><li data-league="11205-mlb"><a href="https://streameast.cool'.self::EVENT.'"><span class="f1--xs MacBaslik">San Diego Padres vs Milwaukee Brewers</span><span>LIVE</span></a></li></ul>';
    }

    private function event(): string
    {
        // Minimal fixture from the observed 2026-10-04 event page. Ads and chat
        // scripts are deliberately not copied into application views.
        return <<<'HTML'
        <script type="application/ld+json">{"@type":"SportsEvent","name":"Milwaukee Brewers vs San Diego Padres","startDate":"2026-10-04T00:30:00+00:00"}</script>
        <div id="Alternatifler">
          <div id="stream-btn-57543" onclick="window.changeStream(57543)">Server1</div>
          <div id="stream-btn-57552" onclick="window.changeStream(57552)">Server2</div>
        </div>
        <script>window.changeStream = function (streamId) {
          document.getElementById('wp_player').src='https://gooz.aapmains.net/new-stream-embed/' + streamId + '?3newa=22'
        }</script>
        <iframe id="wp_player" src="https://gooz.aapmains.net/new-stream-embed/57552?ad=111"></iframe>
        <iframe id="live-chat-iframe" src="https://www.youtube.com/live_chat"></iframe>
        HTML;
    }
}
