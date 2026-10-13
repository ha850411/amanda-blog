<?php

namespace Tests\Feature;

use App\Services\GksService;
use App\Services\GksTransport;
use App\Services\SportsHlsService;
use App\Services\SportsHlsTransport;
use App\Services\SportsPlayerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GksTest extends TestCase
{
    private const EMBED = 'https://embed.st/embed/admin/test-match/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'sports_player.manifest_cache_store' => 'array', 'gks.enabled' => true]);
        Cache::flush();
        $this->travelTo(now()->setDateTime(2026, 10, 12, 21, 30, 0)->utc());
        $this->instance(SportsHlsTransport::class, Mockery::mock(SportsHlsTransport::class)->shouldNotReceive('get')->getMock());
    }

    private function player(?string $url = null, ?string $start = null): string
    {
        return app(SportsPlayerService::class)->register(
            ['id' => 'test-match', 'translated_title' => 'Test match', 'start_at' => $start ?? now()->subMinutes(30)->toIso8601String()],
            ['url' => $url ?? self::EMBED, 'platform' => 'GKStreams / Sportsurge', 'language' => 'Fox', 'number' => 1]
        );
    }

    private function manifest(string $token = 'a'): string
    {
        return 'https://lb12.strmd.st/secure/'.str_repeat($token, 32).'/rtmp/stream/'.str_repeat($token, 64).'/1/playlist.m3u8';
    }

    private function response(string $url, int $rotation = 71): array
    {
        $key = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef';
        $nonce = str_repeat("\x01", 12);
        $cipher = openssl_encrypt($url, 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        $wire = '';
        foreach (str_split(base64_encode($nonce.$cipher.$tag)) as $char) {
            $wire .= chr((ord($char) - 33 + 94 - $rotation) % 94 + 33);
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

    public function test_registration_and_player_render_do_not_fetch_and_expiry_revokes_the_source(): void
    {
        $this->instance(GksTransport::class, Mockery::mock(GksTransport::class)->shouldNotReceive('handshake')->shouldNotReceive('playlist')->getMock());
        $token = $this->player();
        $this->get('/sports/player/'.$token)->assertOk()->assertSee('data-media-adapter="gks"', false)->assertSee('gks-media.js');
        $this->travel(6)->hours();
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertGone();
    }

    public function test_unverified_formats_and_unsafe_embed_urls_remain_unregistered(): void
    {
        foreach (['https://embed.st/embed/admin/test-match/2', 'https://embed.st/embed/hotel/test-match/2', 'https://embed.st/embed/golf/2199/3', self::EMBED.'?x=1', str_replace('embed.st', 'embed.st.attacker.test', self::EMBED)] as $url) {
            $this->assertNull(app(SportsPlayerService::class)->register(['id' => 'test-match', 'start_at' => now()->toIso8601String()], ['url' => $url]));
        }
    }

    public function test_hotel_resolves_only_redirect_headers_and_shares_destinations_across_playlist_refreshes(): void
    {
        $manifest = 'https://lb12.strmd.st/secure/'.str_repeat('a', 32).'/hotel/stream/test-match/1/playlist.m3u8';
        $path = '/m/'.str_repeat('b', 120).'.ts';
        $media = 'https://p16-common-sign.tiktokcdn.com/live.image?signature=abc';
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->once()->andReturn($this->response($manifest));
        $mock->shouldReceive('playlist')->twice()->with($manifest, 262144)->andReturn("#EXTM3U\n#EXTINF:3,\n$path\n");
        $mock->shouldReceive('redirects')->once()->with(['https://lb12.strmd.st'.$path])->andReturn(['https://lb12.strmd.st'.$path => $media]);
        $this->instance(GksTransport::class, $mock);
        $token = $this->player('https://embed.st/embed/hotel/test-match/1');
        $url = '/sports/player/'.$token.'/hls/playlist';
        $this->get($url)->assertOk()->assertSee($media, false)->assertDontSee('/m/')->assertDontSee('/sports/player/');
        $this->travel(3)->seconds();
        $this->get($url)->assertOk()->assertSee($media, false);
    }

    public function test_hotel_rejects_unknown_redirect_destinations_and_never_fetches_media(): void
    {
        $manifest = 'https://lb12.strmd.st/secure/'.str_repeat('a', 32).'/hotel/stream/test-match/1/playlist.m3u8';
        $path = '/m/'.str_repeat('b', 120).'.ts';
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->once()->andReturn($this->response($manifest));
        $mock->shouldReceive('playlist')->once()->andReturn("#EXTM3U\n#EXTINF:3,\n$path\n");
        $mock->shouldReceive('redirects')->once()->andReturn(['https://lb12.strmd.st'.$path => 'https://127.0.0.1/private']);
        $this->instance(GksTransport::class, $mock);
        $token = $this->player('https://embed.st/embed/hotel/test-match/1');
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
    }

    public function test_media_redirects_reject_arbitrary_urls_and_excessive_fanout_before_network(): void
    {
        $this->instance(GksTransport::class, Mockery::mock(GksTransport::class)->shouldNotReceive('redirects')->getMock());
        $source = ['source_url' => self::EMBED, 'manifest_url' => $this->manifest()];
        $cases = [
            ['https://127.0.0.1/private'], ['https://lb13.strmd.st/m/'.str_repeat('b', 120).'.ts'],
            ['https://lb12.strmd.st/m/../private'],
            array_map(fn ($i) => 'https://lb12.strmd.st/m/'.str_repeat('b', 120).$i.'.ts', range(1, 9)),
        ];
        foreach ($cases as $urls) {
            try {
                app(GksService::class)->mediaLocations($urls, $source);
                $this->fail('Unsafe media URL was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hotel_handshake_cannot_switch_to_another_event(): void
    {
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->once()->andReturn($this->response('https://lb12.strmd.st/secure/'.str_repeat('a', 32).'/hotel/stream/another-match/1/playlist.m3u8'));
        $mock->shouldNotReceive('playlist');
        $this->instance(GksTransport::class, $mock);
        $token = $this->player('https://embed.st/embed/hotel/test-match/1');
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
    }

    public function test_hotel_slow_metadata_uses_recent_playlist_without_extending_its_lifetime(): void
    {
        $manifest = 'https://lb12.strmd.st/secure/'.str_repeat('a', 32).'/hotel/stream/test-match/1/playlist.m3u8';
        $path = '/m/'.str_repeat('b', 120).'.ts';
        $later = '/m/'.str_repeat('c', 120).'.ts';
        $media = 'https://p16-common-sign.tiktokcdn.com/live.image';
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->once()->andReturn($this->response($manifest));
        $mock->shouldReceive('playlist')->times(3)->andReturn("#EXTM3U\n#EXTINF:3,\n$path\n", "#EXTM3U\n#EXTINF:3,\n$later\n", "#EXTM3U\n#EXTINF:3,\n$later\n");
        $mock->shouldReceive('redirects')->with(['https://lb12.strmd.st'.$path])->once()->andReturn(['https://lb12.strmd.st'.$path => $media]);
        $mock->shouldReceive('redirects')->with(['https://lb12.strmd.st'.$later])->twice()->andReturn([]);
        $this->instance(GksTransport::class, $mock);
        $token = $this->player('https://embed.st/embed/hotel/test-match/1');
        $url = '/sports/player/'.$token.'/hls/playlist';
        $this->get($url)->assertOk()->assertSee($media, false);
        $this->travel(8)->seconds();
        $this->get($url)->assertOk()->assertSee($media, false);
        $this->travel(5)->seconds();
        $this->get($url)->assertStatus(503);
    }

    public function test_signed_child_playlist_survives_source_refresh_and_video_never_passes_through_server(): void
    {
        $first = $this->manifest('a');
        $second = $this->manifest('b');
        $media = 'https://p16-common-sign.tiktokcdn.com/example.webp?expires=123&signature=abc';
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->twice()->with("\x0a\x05admin\x12\x0atest-match\x1a\x011")->andReturn($this->response($first), $this->response($second));
        $mock->shouldReceive('playlist')->once()->with($first, 262144)->andReturn("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=8000000,RESOLUTION=1920x1080\nhigh/mono.m3u8\n");
        $mock->shouldReceive('playlist')->once()->with(dirname($first).'/high/mono.m3u8', 262144)->andReturn("#EXTM3U\n#EXTINF:3,\n$media\n");
        $mock->shouldReceive('playlist')->once()->with(dirname($second).'/high/mono.m3u8', 262144)->andReturn("#EXTM3U\n#EXTINF:3,\n$media\n");
        $this->instance(GksTransport::class, $mock);
        $token = $this->player();
        $master = $this->get('/sports/player/'.$token.'/hls/playlist')->assertOk()->getContent();
        $child = collect(explode("\n", $master))->first(fn ($line) => str_starts_with($line, '/sports/'));
        $this->assertNotNull($child);
        $this->get($child)->assertOk()->assertSee($media, false)->assertDontSee('/sports/player/');
        $this->get($child)->assertOk(); // Shared playlist and source cache.
        $this->travel(46)->seconds();
        $this->get($child)->assertOk()->assertSee($media, false);
    }

    public function test_decode_authenticates_payload_and_accepts_observed_rotation_versions(): void
    {
        $service = app(GksService::class);
        foreach ([71, 47] as $rotation) {
            $response = $this->response($this->manifest(), $rotation);
            $this->assertSame($this->manifest(), $service->decode($response['body'], $response['key']));
        }
        $response['key'][0] = 'z';
        $this->expectException(RuntimeException::class);
        $service->decode($response['body'], $response['key']);
    }

    public function test_malformed_wire_responses_are_rejected(): void
    {
        $service = app(GksService::class);
        $valid = $this->response($this->manifest());
        foreach (['', "\x12\x02xx", "\x0a\x80", "\x0a\xff\xff\xff\xff\x7f", substr($valid['body'], 0, -1), $valid['body'].'x', str_repeat('x', 16385)] as $body) {
            try {
                $service->decode($body, $valid['key']);
                $this->fail('Invalid response was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_upstream_origins_fail_closed_and_share_failure_backoff(): void
    {
        $mock = Mockery::mock(GksTransport::class);
        $mock->shouldReceive('handshake')->once()->andReturn($this->response('https://127.0.0.1/private.m3u8'));
        $mock->shouldNotReceive('playlist');
        $this->instance(GksTransport::class, $mock);
        $token = $this->player();
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
        $this->get('/sports/player/'.$token.'/hls/playlist')->assertStatus(503);
    }

    public function test_media_or_forged_renditions_cannot_be_requested_through_playlist_routes(): void
    {
        $service = app(SportsHlsService::class);
        $source = ['resolver' => 'gks', 'manifest_url' => $this->manifest(), 'playlist_base' => dirname($this->manifest()).'/'];
        $media = 'https://p16-common-sign.tiktokcdn.com/example.webp';
        $this->assertTrue($service->allowed($media, $source, 'segment'));
        foreach ([$media, 'https://p16-common-sign.tiktokcdn.com.attacker.test/x', 'https://127.0.0.1/a.m3u8'] as $url) {
            $this->assertFalse($service->allowed($url, $source));
        }
        foreach (['../private.m3u8', '/m/payload.ts', 'high/mono.m3u8?override=x', 'https://attacker.test/live.m3u8'] as $variant) {
            $ticket = Crypt::encryptString(json_encode(['variant' => $variant, 'player' => 'player', 'kind' => 'playlist', 'expires' => now()->timestamp + 60]));
            $this->assertNull($service->resource($ticket, 'player', $source));
        }
        $ticket = Crypt::encryptString(json_encode(['url' => $media, 'player' => 'player', 'kind' => 'segment', 'expires' => now()->timestamp + 60]));
        $this->assertNull($service->resource($ticket, 'player', $source));
        $this->expectException(RuntimeException::class);
        $service->load($source, $media, 'playlist');
    }
}
