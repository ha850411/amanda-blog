<?php

namespace Tests\Feature;

use App\Services\SportsHlsTransport;
use App\Services\SportsPlayerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class SportsHlsTest extends TestCase
{
    private const EMBED = 'https://gooz.aapmains.net/new-stream-embed/test';

    private const ROOT = 'https://media.example.test/channel/live.m3u8';

    private array $source;

    private string $token;

    private $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = [
            'event_id' => 'test-game', 'manifest_url' => self::ROOT,
            'allowed_prefixes' => ['https://media.example.test/channel/', 'https://cdn.example.test/video/'],
            'expires_at' => now()->addHours(3)->toIso8601String(),
        ];
        config([
            'cache.default' => 'array', 'session.driver' => 'array',
            'sports_player.manifest_cache_store' => 'array',
            'sports_player.hls_sources' => [self::EMBED => $this->source],
        ]);
        Cache::flush();
        $this->transport = Mockery::mock(SportsHlsTransport::class);
        $this->app->instance(SportsHlsTransport::class, $this->transport);
        $this->token = app(SportsPlayerService::class)->register([
            'id' => 'test-game', 'translated_title' => '測試賽事',
        ], ['url' => self::EMBED, 'platform' => 'Test', 'language' => 'English', 'number' => 1]);
    }

    public function test_rendering_player_does_not_fetch_media_server_side(): void
    {
        $this->transport->shouldNotReceive('get');
        $this->get('/sports/player/'.$this->token)->assertOk()->assertSee('id="sports-artplayer"', false)
            ->assertSee('data-mode="hls"', false)->assertSee('sports-live-player.js')
            ->assertSee('data-ui-library=', false)->assertSee('artplayer-5.4.0.js')
            ->assertDontSee('<video', false)->assertDontSee('<iframe', false)
            ->assertDontSee('<script src="http://localhost/vendor/artplayer', false);
    }

    public function test_playlist_keeps_video_and_keys_on_the_source_cdn_and_reuses_cache(): void
    {
        $body = "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://cdn.example.test/video/key\"\n#EXTINF:6,\nhttps://cdn.example.test/video/1.ts\n";
        $this->transport->shouldReceive('get')->once()->with(self::ROOT, 262144)->andReturn($body);
        foreach ([1, 2] as $viewer) {
            $this->get($this->playlist())->assertOk()->assertSee('https://cdn.example.test/video/1.ts', false)
                ->assertSee('https://cdn.example.test/video/key', false)->assertDontSee('/hls/resource')
                ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_child_playlists_are_signed_and_relative_segments_become_absolute(): void
    {
        $this->transport->shouldReceive('get')->once()->with(self::ROOT, 262144)
            ->andReturn("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=5000000\nhigh/live.m3u8\n");
        $body = $this->get($this->playlist())->assertOk()->getContent();
        $url = trim(explode("\n", $body)[2]);
        $this->assertStringStartsWith('/sports/player/'.$this->token.'/hls/resource?', $url);
        $this->transport->shouldReceive('get')->once()->with('https://media.example.test/channel/high/live.m3u8', 262144)
            ->andReturn("#EXTM3U\n#EXTINF:6,\nsegment.ts\n");
        // A long-running live player retains its child URL beyond three minutes.
        $this->travel(4)->minutes();
        $this->get($url)->assertOk()->assertSee('https://media.example.test/channel/high/segment.ts', false);
    }

    public function test_resource_tickets_cannot_be_used_to_relay_video_or_foreign_urls(): void
    {
        $this->transport->shouldNotReceive('get');
        foreach ([
            ['kind' => 'segment'], ['kind' => 'key'], ['player' => str_repeat('a', 64)],
            ['expires' => now()->timestamp - 1], ['url' => 'https://127.0.0.1/private'],
            ['url' => 'https://media.example.test.attacker.test/channel/a'],
        ] as $override) {
            $ticket = Crypt::encryptString(json_encode(array_merge([
                'url' => self::ROOT, 'kind' => 'playlist', 'player' => $this->token, 'expires' => now()->timestamp + 100,
            ], $override)));
            $this->get('/sports/player/'.$this->token.'/hls/resource?'.http_build_query(['ticket' => $ticket]))->assertNotFound();
        }
        $this->get('/sports/player/'.$this->token.'/hls/resource?ticket=invalid')->assertNotFound();
    }

    public function test_unexpected_hosts_or_unsafe_paths_in_upstream_playlists_fail_closed(): void
    {
        foreach (['https://127.0.0.1/a', 'https://cdn.example.test.evil.test/video/a',
            'https://user@cdn.example.test/video/a', 'https://cdn.example.test:444/video/a',
            'http://cdn.example.test/video/a', 'https://cdn.example.test/video/%2e%2e/private',
            'https://cdn.example.test/video/../../private'] as $url) {
            Cache::flush();
            $this->token = app(SportsPlayerService::class)->register(['id' => 'test-game'], ['url' => self::EMBED]);
            $this->transport->shouldReceive('get')->once()->with(self::ROOT, 262144)->andReturn("#EXTM3U\n#EXTINF:6,\n".$url);
            $this->get($this->playlist())->assertStatus(503)->assertDontSee($url, false);
        }
    }

    public function test_upstream_errors_are_bounded_and_temporarily_cached(): void
    {
        $this->transport->shouldReceive('get')->once()->andThrow(new \RuntimeException('private upstream detail'));
        for ($i = 0; $i < 2; $i++) {
            $this->get($this->playlist())->assertStatus(503)->assertHeader('Retry-After', '2')->assertDontSee('private upstream detail');
        }
    }

    public function test_non_playlist_bodies_are_never_returned(): void
    {
        $this->transport->shouldReceive('get')->once()->andReturn('<html>unexpected upstream</html>');
        $this->get($this->playlist())->assertStatus(503)->assertDontSee('unexpected upstream');
    }

    public function test_source_expiry_or_removal_revokes_all_endpoints_without_fetching(): void
    {
        $this->transport->shouldNotReceive('get');
        $this->travel(4)->hours();
        $this->get($this->playlist())->assertStatus(410);
        $this->travelBack();
        config(['sports_player.hls_sources' => []]);
        $this->get($this->playlist())->assertStatus(410);
        $this->get('/sports/player/'.$this->token)->assertStatus(410);
    }

    private function playlist(): string
    {
        return '/sports/player/'.$this->token.'/hls/playlist';
    }
}
