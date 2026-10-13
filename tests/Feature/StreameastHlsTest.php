<?php

namespace Tests\Feature;

use App\Services\SportsHlsService;
use App\Services\SportsPlayerService;
use App\Services\StreameastHlsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreameastHlsTest extends TestCase
{
    private const EMBED = 'https://gooz.aapmains.net/new-stream-embed/57989?ad=111';

    private const ENTRY = 'https://chatgpt.hereisman.net/playlist/57989/load-playlist';

    private const MEDIA = 'https://pl.playlist3.space/playlist/57989/a.newstore4.space/caxi';

    private const SEGMENT = 'https://a.newstore4.space/scripts/NTc5ODk=/p1791851391349247892_1429.txt';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array', 'sports_player.manifest_cache_store' => 'array', 'streameast.enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_registration_and_rendering_do_not_fetch_and_are_bound_to_the_match(): void
    {
        $token = $this->register();
        $this->get('/sports/player/'.$token)->assertOk()->assertSee('data-mode="hls"', false)->assertDontSee('<iframe', false);
        Http::assertNothingSent();
        $service = app(StreameastHlsService::class);
        $player = app(SportsPlayerService::class)->find($token);
        $player['event_id'] = 'different-game';
        $this->assertNull($service->descriptor($player));
        $this->travel(7)->hours();
        $this->get($this->playlist($token))->assertStatus(410);
        Http::assertNothingSent();
    }

    public function test_unobserved_embed_routes_or_parameters_are_not_enabled(): void
    {
        $player = app(SportsPlayerService::class)->find($this->register());
        foreach (['https://gooz.aapmains.net/new-stream-embed/57989?redirect=https://attacker.test',
            'https://gooz.aapmains.net/new-stream-embed/57989#fragment',
            'https://gooz.aapmains.net/new-stream-embed/%35%37%39%38%39',
            'https://gooz.aapmains.net/new-stream-embed/../57989',
            'https://gooz.aapmains.net:443/new-stream-embed/57989',
            'https://gooz.aapmains.net.attacker.test/new-stream-embed/57989',
            'https://user@gooz.aapmains.net/new-stream-embed/57989',
            'https://gooz.aapmains.net/admin/57989'] as $url) {
            $player['stream']['url'] = $url;
            $this->assertNull(app(StreameastHlsService::class)->descriptor($player));
        }
        Http::assertNothingSent();
    }

    public function test_live_playlist_resolves_current_channel_and_leaves_video_on_cdn(): void
    {
        $this->fake([
            self::EMBED => fn () => Http::response($this->embed()),
            self::ENTRY => fn () => Http::response($this->master()),
            self::MEDIA => fn () => Http::response($this->media()),
        ]);
        $token = $this->register();
        foreach ([1, 2] as $viewer) {
            $this->get($this->playlist($token))->assertOk()->assertSee(self::SEGMENT, false)
                ->assertDontSee('/hls/resource')->assertDontSee('load-playlist')->assertHeaderMissing('Set-Cookie');
        }
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === self::ENTRY
            && $request->hasHeader('Origin', 'https://gooz.aapmains.net')
            && $request->hasHeader('Referer', 'https://gooz.aapmains.net/'));
        Http::assertSent(fn ($request) => $request->url() === self::MEDIA
            && $request->hasHeader('Origin', 'https://gooz.aapmains.net')
            && $request->hasHeader('Referer', 'https://gooz.aapmains.net/'));
        Http::assertNotSent(fn ($request) => $request->url() === self::SEGMENT);
    }

    public function test_same_player_url_survives_a_change_of_cdn_without_guessing_urls(): void
    {
        $next = 'https://pl.wearestrong1.space/playlist/57989/a.newstore1.space/caxi';
        $segment = str_replace('a.newstore4.space', 'a.newstore1.space', self::SEGMENT);
        $this->fake([
            self::EMBED => fn () => Http::response($this->embed()),
            self::ENTRY => Http::sequence()->push($this->master())->push($this->master($next)),
            self::MEDIA => fn () => Http::response($this->media()),
            $next => fn () => Http::response($this->media($segment)),
        ]);
        $token = $this->register();
        $this->get($this->playlist($token))->assertOk()->assertSee(self::SEGMENT, false);
        $this->travel(46)->seconds();
        $this->get($this->playlist($token))->assertOk()->assertSee($segment, false);
        Http::assertSentCount(6);
    }

    public function test_brief_entry_failure_uses_fresh_media_from_previous_origin_without_extending_expiry(): void
    {
        $sequence = 1429;
        $this->fake([
            self::EMBED => fn () => Http::response($this->embed()),
            self::ENTRY => Http::sequence()->push($this->master())->pushStatus(503)->pushStatus(503),
            self::MEDIA => function () use (&$sequence) {
                return Http::response($this->media(str_replace('_1429.txt', '_'.$sequence++.'.txt', self::SEGMENT)));
            },
        ]);
        $token = $this->register();
        $this->get($this->playlist($token))->assertOk()->assertSee('_1429.txt', false);
        $this->travel(46)->seconds();
        $this->get($this->playlist($token))->assertOk()->assertSee('_1430.txt', false);
        Http::assertSentCount(6);
        // Passing through backoff must not renew the previous origin's TTL.
        $this->get($this->playlist($token))->assertOk();
        Http::assertSentCount(6);
        $this->travel(135)->seconds();
        $this->get($this->playlist($token))->assertStatus(503);
        Http::assertSentCount(8);
    }

    public function test_observed_direct_playlist_literal_also_works_without_fetching_entry(): void
    {
        $this->fake([
            self::EMBED => fn () => Http::response("source: window.atob('".base64_encode(self::MEDIA)."')"),
            self::MEDIA => fn () => Http::response($this->media()),
        ]);
        $this->get($this->playlist($this->register()))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_unrecognized_players_conflicting_sources_or_other_channels_fail_closed(): void
    {
        foreach (['<script>evil()</script>', 'const source = "https://127.0.0.1/private";',
            $this->embed(str_replace('57989', '57990', self::ENTRY)),
            $this->embed().';const source = "'.self::MEDIA.'";',
            $this->embed('https://chatgpt.hereisman.net.attacker.test/playlist/57989/load-playlist')] as $html) {
            Cache::flush();
            $this->fake([self::EMBED => fn () => Http::response($html)]);
            $this->get($this->playlist($this->register()))->assertStatus(503);
            Http::assertSentCount(1);
        }
    }

    public function test_unknown_redirected_or_multiple_renditions_are_not_followed(): void
    {
        foreach ([$this->master('https://127.0.0.1/private'),
            $this->master(str_replace('57989', '57990', self::MEDIA)),
            $this->master()."#EXT-X-STREAM-INF:BANDWIDTH=900000\n".self::MEDIA,
            "#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,URI=\"https://attacker.test/a\"\n".$this->master()] as $master) {
            Cache::flush();
            $this->fake([self::EMBED => fn () => Http::response($this->embed()), self::ENTRY => fn () => Http::response($master)]);
            $this->get($this->playlist($this->register()))->assertStatus(503);
            Http::assertSentCount(2);
        }
        Cache::flush();
        $this->fake([self::EMBED => fn () => Http::response('', 302, ['Location' => 'https://attacker.test'])]);
        $this->get($this->playlist($this->register()))->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_segment_allowlist_is_channel_bound_and_cannot_relay_media(): void
    {
        $token = $this->register();
        $source = app(StreameastHlsService::class)->descriptor(app(SportsPlayerService::class)->find($token));
        $hls = app(SportsHlsService::class);
        $this->assertTrue($hls->allowed(self::SEGMENT, $source, 'segment'));
        foreach ([str_replace('NTc5ODk=', 'NTc5OTA=', self::SEGMENT),
            str_replace('newstore4.space', 'newstore4.space.attacker.test', self::SEGMENT),
            self::SEGMENT.'?url=http://127.0.0.1', str_replace('/p179', '/%2e%2e/p179', self::SEGMENT)] as $url) {
            $this->assertFalse($hls->allowed($url, $source, 'segment'));
        }
        $ticket = Crypt::encryptString(json_encode(['player' => $token, 'expires' => now()->timestamp + 100, 'kind' => 'segment', 'url' => self::SEGMENT]));
        $this->assertNull($hls->resource($ticket, $token, $source));
        Http::assertNothingSent();
    }

    public function test_removed_origins_revoke_cached_sources_and_failure_backs_off(): void
    {
        $this->fake([self::EMBED => fn () => Http::response($this->embed()), self::ENTRY => fn () => Http::response($this->master()), self::MEDIA => fn () => Http::response($this->media())]);
        $token = $this->register();
        $this->get($this->playlist($token))->assertOk();
        config(['streameast.playlist_hosts' => []]);
        $this->get($this->playlist($token))->assertStatus(503);
        Http::assertSentCount(3);
        Cache::flush();
        $this->fake([self::EMBED => fn () => Http::response('down', 503)]);
        $token = $this->register();
        $this->get($this->playlist($token))->assertStatus(503);
        $this->get($this->playlist($token))->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_oversized_source_and_media_with_foreign_channel_are_rejected(): void
    {
        $this->fake([self::EMBED => fn () => Http::response(str_repeat('x', 262145))]);
        $this->get($this->playlist($this->register()))->assertStatus(503);
        Cache::flush();
        $this->fake([self::EMBED => fn () => Http::response($this->embed()), self::ENTRY => fn () => Http::response($this->master()),
            self::MEDIA => fn () => Http::response($this->media(str_replace('NTc5ODk=', 'NTc5OTA=', self::SEGMENT)))]);
        $this->get($this->playlist($this->register()))->assertStatus(503);
        Http::assertSentCount(3);
    }

    private function fake(array $responses): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function register(): string
    {
        return app(SportsPlayerService::class)->register(['id' => 'rays-guardians', 'translated_title' => '測試賽事'], [
            'url' => self::EMBED, 'resolver' => 'streameast', 'event_id' => 'rays-guardians',
            'event_expires_at' => now()->addHours(6)->toIso8601String(),
            'platform' => 'Streameast', 'language' => 'English', 'number' => 1,
        ]);
    }

    private function playlist(string $token): string
    {
        return '/sports/player/'.$token.'/hls/playlist';
    }

    private function embed(string $url = self::ENTRY): string
    {
        return 'const source = "'.$url.'"; source: window.atob(\''.base64_encode($url).'\');';
    }

    private function master(string $url = self::MEDIA): string
    {
        return "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=4000000,RESOLUTION=1280x720\n".$url."\n";
    }

    private function media(string $url = self::SEGMENT): string
    {
        return "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:1429\n#EXTINF:6,\n".$url."\n";
    }
}
