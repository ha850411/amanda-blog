<?php

namespace Tests\Feature;

use App\Services\SportsPlayerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SportsPlayerTest extends TestCase
{
    private const EMBED = 'https://embed.st/embed/admin/ppv-ufc-332-silva-vs-wang/1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        // Test fixtures exercise the supported path; production has no verified URLs.
        config(['sports_player.verified_embed_urls' => [self::EMBED, 'https://gooz.aapmains.net/new-stream-embed/57551']]);
        Cache::flush();
        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_player_supplies_client_embed_without_fetching_upstream(): void
    {
        foreach ([self::EMBED, 'https://gooz.aapmains.net/new-stream-embed/57551'] as $embed) {
            $this->get('/sports/player/'.$this->register($embed))->assertOk()
                ->assertSee('Easonn')->assertSee('SPORTS')->assertSee('網頁全螢幕')
                ->assertSee('螢幕全螢幕')->assertSee('原連結')->assertSee('停止播放')
                ->assertSee('data-embed-url="'.$embed.'"', false)
                ->assertSee('<iframe', false)->assertDontSee('<video', false)
                ->assertSee('sandbox="allow-scripts allow-same-origin allow-presentation"', false)
                ->assertDontSee('src="'.$embed.'"', false)
                ->assertDontSee('allow-popups')->assertDontSee('allow-top-navigation')
                ->assertDontSee('/sports/media')->assertDontSee('data-source-url')
                ->assertDontSee('hls-1.7.3')->assertDontSee('回到部落格')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        Http::assertNothingSent();
    }

    public function test_retired_source_and_media_endpoints_cannot_relay_even_a_valid_old_ticket(): void
    {
        $ticket = Crypt::encryptString(json_encode([
            'url' => 'https://chatgpt.hereisman.net/playlist/57551/load-playlist',
            'origin' => 'https://gooz.aapmains.net',
            'expires' => now()->timestamp + 3600,
        ], JSON_THROW_ON_ERROR));

        $this->getJson('/sports/player/'.$this->register().'/source')->assertNotFound();
        $this->get('/sports/media?'.http_build_query(['ticket' => $ticket]), ['Range' => 'bytes=0-1023'])->assertNotFound();
        $this->get('/sports/media?url=https://embed.st/live.m3u8')->assertNotFound();
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeEmbedUrls')]
    public function test_unsafe_cached_player_urls_are_not_rendered_or_fetched(string $url): void
    {
        config(['sports_player.verified_embed_urls' => [$url]]);
        $token = str_repeat('a', 64);
        Cache::put('sports:player:'.$token, ['stream' => ['url' => $url]], 60);
        $this->assertNull(app(SportsPlayerService::class)->register(['id' => 'test-match'], ['url' => $url]));
        $this->get('/sports/player/'.$token)->assertStatus(410)
            ->assertSee('播放器連結已過期或無法使用')
            ->assertDontSee('<iframe', false)->assertDontSee('data-embed-url');
        Http::assertNothingSent();
    }

    public static function unsafeEmbedUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://embed.st/embed/a',
            'https://embed.st.attacker.test/embed/a',
            'https://embed.st@127.0.0.1/a',
            'https://user:pass@embed.st/embed/a',
            'https://embed.st:8443/embed/a',
            'https://127.0.0.1/a',
            'https://localhost/private',
            'https://embed.st/embed/a#fragment',
            'javascript:alert(1)',
        ]);
    }

    public function test_cached_player_is_revalidated_after_an_embed_host_is_removed(): void
    {
        $token = $this->register();
        config(['sports.embed_hosts' => []]);
        $this->get('/sports/player/'.$token)->assertStatus(410)->assertDontSee('<iframe', false);
        Http::assertNothingSent();
    }

    public function test_expired_or_missing_player_does_not_load_or_fetch_a_source(): void
    {
        $this->get('/sports/player/'.str_repeat('0', 64))->assertStatus(410);
        $token = $this->register();
        $this->travel(config('sports_player.lifetime_seconds') + 1)->seconds();
        $this->get('/sports/player/'.$token)->assertStatus(410)
            ->assertSee('播放器連結已過期')->assertDontSee('<iframe', false);
        Http::assertNothingSent();
    }

    public function test_unverified_sources_are_not_registered_even_on_an_allowed_host(): void
    {
        $players = app(SportsPlayerService::class);
        foreach ([null, 'https://embed.st/embed/admin/unverified/1', self::EMBED.'?other=1'] as $url) {
            $this->assertNull($players->register(['id' => 'test-match'], ['url' => $url]));
        }
        config(['sports_player.verified_embed_urls' => []]);
        $this->assertNull($players->register(['id' => 'test-match'], ['url' => self::EMBED]));
        Http::assertNothingSent();
    }

    public function test_old_player_links_stop_working_when_playback_support_is_removed(): void
    {
        $token = $this->register();
        config(['sports_player.verified_embed_urls' => []]);
        $this->get('/sports/player/'.$token)->assertStatus(410)
            ->assertSee('播放器連結已過期或無法使用')->assertDontSee('<iframe', false);
        Http::assertNothingSent();
    }

    public function test_upstream_metadata_is_escaped_in_player_and_iframe_title(): void
    {
        $token = app(SportsPlayerService::class)->register([
            'id' => 'test-match', 'translated_title' => '"><script>alert(1)</script>',
        ], ['url' => self::EMBED, 'platform' => '<svg onload=alert(1)>', 'language' => 'English', 'number' => 1]);
        $this->get('/sports/player/'.$token)->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<svg onload=alert(1)>', false);
        Http::assertNothingSent();
    }

    private function register(string $url = self::EMBED): string
    {
        return app(SportsPlayerService::class)->register(['id' => 'test-match', 'translated_title' => '測試賽事'], [
            'url' => $url, 'platform' => 'Sportsurge', 'language' => 'English', 'number' => 1,
        ]);
    }
}
