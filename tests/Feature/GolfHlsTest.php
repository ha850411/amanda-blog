<?php

namespace Tests\Feature;

use App\Services\GksTransport;
use App\Services\SportsHlsService;
use App\Services\SportsPlayerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GolfHlsTest extends TestCase
{
    private const EMBED = 'https://embed.st/embed/golf/2195/1';

    private const ROCKY = 'https://rockylive.st/source/streamed1.php?hd=528&id=2195&no=1';

    private const TARGET = 'https://embed.st/embed/ingest/streamedutahjazz/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'sports_player.manifest_cache_store' => 'array',
            'gks.enabled' => true, 'gks.golf_enabled' => true]);
        Cache::flush();
    }

    public function test_registration_is_lazy_and_uses_plain_hls_with_event_expiry(): void
    {
        $this->instance(GksTransport::class, Mockery::mock(GksTransport::class)->shouldNotReceive('page')->shouldNotReceive('handshake')->getMock());
        $token = $this->player();
        $this->assertNull(app(SportsPlayerService::class)->find($token)['media_adapter']);
        $this->get('/sports/player/'.$token)->assertOk()->assertSee('data-mode="hls"', false)->assertDontSee('data-media-adapter="gks"', false);
        config(['gks.golf_enabled' => false]);
        $this->get($this->playlist($token))->assertGone();
        config(['gks.golf_enabled' => true]);
        $this->travel(7)->hours();
        $this->get($this->playlist($token))->assertGone();
    }

    public function test_only_observed_golf_channels_are_registered(): void
    {
        foreach ([self::EMBED.'?x=1', self::EMBED.'#x', str_replace('/1', '/3', self::EMBED),
            str_replace('/2195/', '/jazz/', self::EMBED), str_replace('embed.st', 'embed.st.attacker.test', self::EMBED),
            str_replace('/2195/', '/%32%31%39%35/', self::EMBED), self::TARGET] as $url) {
            $this->assertNull($this->player($url));
        }
        $this->assertNotNull($this->player('https://embed.st/embed/golf/2195/2'));
    }

    public function test_ingest_resolution_reuses_mapping_refreshes_signature_and_delivers_media_directly(): void
    {
        $first = $this->manifest('a');
        $next = str_replace('lb15.', 'lb22.', $this->manifest('b'));
        $mock = $this->mapping();
        $mock->shouldReceive('handshake')->twice()->with("\x0a\x06ingest\x12\x10streamedutahjazz\x1a\x011")
            ->andReturn($this->response($first), $this->response($next));
        $mock->shouldReceive('playlist')->once()->with($first, 262144)->andReturn($this->media());
        $mock->shouldReceive('playlist')->once()->with($next, 262144)->andReturn($this->media());
        $mock->shouldNotReceive('redirects');
        $this->instance(GksTransport::class, $mock);
        $url = $this->playlist($this->player());
        $this->get($url)->assertOk()->assertSee('https://lb15.strmd.st/m/', false)->assertDontSee('/hls/resource')->assertHeaderMissing('Set-Cookie');
        $this->get($url)->assertOk();
        $this->travel(46)->seconds();
        $this->get($url)->assertOk()->assertSee('https://lb22.strmd.st/m/', false);
    }

    public function test_mapping_refresh_follows_only_the_new_observed_ingest_target(): void
    {
        $first = $this->manifest('a');
        $next = str_replace('streamedutahjazz', 'anotherobservedslug', $this->manifest('b'));
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('page')->twice()->with(self::EMBED, 1048576)->andReturn($this->frame());
        $mock->shouldReceive('page')->twice()->with(self::ROCKY, 65536)->andReturn($this->target(), $this->target(str_replace('streamedutahjazz', 'anotherobservedslug', self::TARGET)));
        $mock->shouldReceive('handshake')->twice()->andReturn($this->response($first), $this->response($next));
        $mock->shouldReceive('playlist')->once()->with($first, 262144)->andReturn($this->media());
        $mock->shouldReceive('playlist')->once()->with($next, 262144)->andReturn($this->media());
        $this->instance(GksTransport::class, $mock);
        $url = $this->playlist($this->player());
        $this->get($url)->assertOk();
        $this->travel(121)->seconds();
        $this->get($url)->assertOk();
    }

    public function test_direct_player_uses_actual_golf_slot_without_guessing_an_ingest_slug(): void
    {
        $manifest = str_replace('/ingest/stream/streamedutahjazz/', '/golf/stream/2195/', $this->manifest());
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('page')->once()->with(self::EMBED, 1048576)
            ->andReturn('<div id="player"></div><script src="https://strmd.b-cdn.net/js/bundle-jw.js"></script>');
        $mock->shouldReceive('handshake')->once()->with("\x0a\x04golf\x12\x042195\x1a\x011")->andReturn($this->response($manifest));
        $mock->shouldReceive('playlist')->once()->with($manifest, 262144)->andReturn($this->media());
        $this->instance(GksTransport::class, $mock);
        $this->get($this->playlist($this->player()))->assertOk();
    }

    public function test_unknown_ambiguous_and_cross_channel_frames_do_not_fetch_targets(): void
    {
        foreach ([$this->frame('https://127.0.0.1/private'), $this->frame(str_replace('id=2195', 'id=2194', self::ROCKY)),
            $this->frame(str_replace('no=1', 'no=2', self::ROCKY)), $this->frame(self::ROCKY.'&extra=1'),
            $this->frame(str_replace('rockylive.st', 'rockylive.st.attacker.test', self::ROCKY)),
            $this->frame().$this->frame(), '<script>unrecognized()</script>'] as $html) {
            Cache::flush();
            $mock = Mockery::mock(GksTransport::class);
            $mock->shouldReceive('page')->once()->with(self::EMBED, 1048576)->andReturn($html);
            $mock->shouldNotReceive('handshake');
            $this->instance(GksTransport::class, $mock);
            $this->get($this->playlist($this->player()))->assertStatus(503);
        }
    }

    public function test_unsafe_missing_and_multiple_ingest_targets_are_rejected_without_handshake(): void
    {
        foreach ([$this->target('https://127.0.0.1/x'), $this->target(self::TARGET.'?x=1'),
            $this->target(str_replace('/1', '/2', self::TARGET)), $this->target(self::EMBED),
            $this->target().$this->target(), '<script>playerFrame.src = computeTarget()</script>'] as $page) {
            Cache::flush();
            $mock = $this->mapping($page);
            $mock->shouldNotReceive('handshake');
            $this->instance(GksTransport::class, $mock);
            $this->get($this->playlist($this->player()))->assertStatus(503);
        }
    }

    public function test_handshake_cannot_change_target_channel_provider_or_host(): void
    {
        foreach ([str_replace('streamedutahjazz', 'another', $this->manifest()),
            str_replace('/ingest/', '/golf/', $this->manifest()), str_replace('/1/', '/2/', $this->manifest()),
            str_replace('strmd.st', 'strmd.st.attacker.test', $this->manifest())] as $manifest) {
            Cache::flush();
            $mock = $this->mapping();
            $mock->shouldReceive('handshake')->once()->andReturn($this->response($manifest));
            $mock->shouldNotReceive('playlist');
            $this->instance(GksTransport::class, $mock);
            $this->get($this->playlist($this->player()))->assertStatus(503);
        }
    }

    public function test_failure_backoff_expires_and_does_not_cache_a_broken_mapping(): void
    {
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('page')->twice()->with(self::EMBED, 1048576)->andThrow(new RuntimeException('unavailable'));
        $mock->shouldNotReceive('handshake');
        $this->instance(GksTransport::class, $mock);
        $url = $this->playlist($this->player());
        $this->get($url)->assertStatus(503);
        $this->get($url)->assertStatus(503);
        $this->travel(16)->seconds();
        $this->get($url)->assertStatus(503);
    }

    public function test_media_is_limited_to_current_origin_and_cannot_be_relayed(): void
    {
        $hls = app(SportsHlsService::class);
        $source = ['resolver' => 'golf', 'manifest_url' => $this->manifest(), 'media_origin' => 'https://lb15.strmd.st'];
        $media = 'https://lb15.strmd.st/m/'.str_repeat('x', 120).'.ts';
        $this->assertTrue($hls->allowed($media, $source, 'segment'));
        foreach ([str_replace('lb15.', 'lb16.', $media), $media.'?url=http://localhost', $media.'#fragment',
            str_replace('/m/', '/m/%2e%2e/', $media), str_replace('strmd.st', 'strmd.st.attacker.test', $media),
            str_replace('https://', 'https://user@', $media)] as $url) {
            $this->assertFalse($hls->allowed($url, $source, 'segment'));
        }
        $this->assertFalse($hls->allowed($media, $source));
        $this->assertFalse($hls->allowed($media, $source, 'key'));
        $ticket = Crypt::encryptString(json_encode(['player' => 'player', 'expires' => now()->timestamp + 60, 'kind' => 'playlist', 'url' => $media]));
        $this->assertNull($hls->resource($ticket, 'player', $source));
        $this->expectException(RuntimeException::class);
        $hls->load($source, $media, 'playlist');
    }

    public function test_unrecognized_segment_in_upstream_playlist_fails_closed(): void
    {
        $mock = $this->mapping();
        $mock->shouldReceive('handshake')->once()->andReturn($this->response($this->manifest()));
        $mock->shouldReceive('playlist')->once()->andReturn("#EXTM3U\n#EXTINF:4,\nhttps://attacker.test/video.ts\n");
        $this->instance(GksTransport::class, $mock);
        $this->get($this->playlist($this->player()))->assertStatus(503);
    }

    public function test_transport_rejects_arbitrary_page_urls_before_network(): void
    {
        $transport = new GksTransport;
        foreach (['https://127.0.0.1/private', self::ROCKY.'&extra=1', self::TARGET,
            'https://embed.st/embed/golf/../1', str_replace('rockylive.st', 'rockylive.st.attacker.test', self::ROCKY)] as $url) {
            try {
                $transport->page($url, 1024);
                $this->fail('Unexpected request');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function mapping(?string $page = null): \Mockery\MockInterface
    {
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('page')->once()->with(self::EMBED, 1048576)
            ->andReturn('<script>const ad="<iframe src=\"https://attacker.test\">";</script><iframe src="/ad.html"></iframe>'.$this->frame());
        $mock->shouldReceive('page')->once()->with(self::ROCKY, 65536)->andReturn($page ?? $this->target());

        return $mock;
    }

    private function frame(string $url = self::ROCKY): string
    {
        return '<iframe src="'.htmlspecialchars($url, ENT_QUOTES).'" allowfullscreen></iframe>';
    }

    private function target(string $url = self::TARGET): string
    {
        return '<script>playerFrame.src = atob("'.base64_encode($url).'");</script>';
    }

    private function player(string $url = self::EMBED): ?string
    {
        return app(SportsPlayerService::class)->register(['id' => 'jazz-spurs', 'translated_title' => 'Jazz vs Spurs', 'start_at' => now()->subHour()->toIso8601String()], ['url' => $url, 'platform' => 'GKStreams / Sportsurge', 'language' => 'English', 'number' => 1]);
    }

    private function playlist(string $token): string
    {
        return '/sports/player/'.$token.'/hls/playlist';
    }

    private function manifest(string $token = 'a'): string
    {
        return 'https://lb15.strmd.st/secure/'.str_repeat($token, 32).'/ingest/stream/streamedutahjazz/1/playlist.m3u8';
    }

    private function media(): string
    {
        return "#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXTINF:4.004,\n/m/".str_repeat('x', 120).".ts\n";
    }

    private function response(string $url): array
    {
        $key = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef';
        $nonce = str_repeat("\x01", 12);
        $cipher = openssl_encrypt($url, 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        $wire = '';
        foreach (str_split(base64_encode($nonce.$cipher.$tag)) as $char) {
            $wire .= chr((ord($char) - 33 + 94 - 71) % 94 + 33);
        }
        $size = strlen($wire);
        $length = '';
        do {
            $byte = $size & 127;
            $size >>= 7;
            $length .= chr($byte | ($size ? 128 : 0));
        } while ($size);

        return ['body' => "\x0a".$length.$wire, 'key' => $key];
    }
}
