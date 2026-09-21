<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/analytics/articles?start=2026-09-20&end=2026-09-21';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Taipei'));
        config()->set('cloudflare.api_token', 'private-test-token');
        config()->set('cloudflare.account_id', str_repeat('a', 32));
        config()->set('cloudflare.site_tag', null);
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function login(): void
    {
        $this->actingAs(Admin::create(['username' => 'analytics-admin', 'password' => 'test']), 'admin');
    }

    private function response(array $articles = [], array $hourly = []): array
    {
        return ['data' => ['viewer' => ['accounts' => [[
            'articles' => $articles, 'hourly' => $hourly,
        ]]]], 'errors' => null];
    }

    public function test_dashboard_and_analytics_require_admin_authentication(): void
    {
        Http::fake();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->getJson(self::URL)->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_dashboard_renders_without_exposing_credentials(): void
    {
        $this->login();
        DB::table('about')->insert(['title' => 'Amanda', 'picture' => '/images/favicon.png']);
        $this->get('/admin')->assertOk()
            ->assertSee('文章流量總覽')
            ->assertSee('admin-analytics.css')
            ->assertViewHas('analyticsToday', '2026-09-21')
            ->assertViewHas('analyticsStart', '2026-09-21')
            ->assertDontSee('private-test-token');
    }

    public function test_missing_configuration_is_not_reported_as_zero_traffic(): void
    {
        $this->login();
        config()->set('cloudflare.api_token', '');
        Http::fake();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('status', 'not_configured')->assertJsonMissingPath('data');
        Http::assertNothingSent();
    }

    public function test_views_are_mapped_to_articles_and_utc_hours_to_taiwan_days(): void
    {
        $this->login();
        $a = Article::factory()->create(['title' => '熱門文章', 'status' => 1]);
        $b = Article::factory()->create(['title' => '密碼文章', 'status' => 2]);
        Article::factory()->create(['title' => '無瀏覽文章', 'status' => 0]);
        Http::fake(['api.cloudflare.com/*' => Http::response($this->response([
            ['count' => 20, 'dimensions' => ['requestPath' => '/article/'.$a->id], 'avg' => ['sampleInterval' => 10]],
            ['count' => 10, 'dimensions' => ['requestPath' => '/article/'.$a->id.'/']],
            ['count' => 8, 'dimensions' => ['requestPath' => '/article/'.$b->id]],
        ], [
            ['count' => 8, 'dimensions' => ['datetimeHour' => '2026-09-20T15:00:00Z']],
            ['count' => 30, 'dimensions' => ['datetimeHour' => '2026-09-20T16:00:00Z']],
        ]))]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data.summary.page_views', 38)
            ->assertJsonPath('data.summary.viewed_articles', 2)
            ->assertJsonPath('data.summary.average_daily_views', 19)
            ->assertJsonPath('data.sampled', true)
            ->assertJsonPath('data.articles.0.id', $a->id)
            ->assertJsonPath('data.articles.0.page_views', 30)
            ->assertJsonPath('data.articles.0.share', 78.9)
            ->assertJsonPath('data.articles.2.page_views', 0)
            ->assertJsonPath('data.daily.0.date', '2026-09-20')
            ->assertJsonPath('data.daily.0.page_views', 8)
            ->assertJsonPath('data.daily.1.page_views', 30)
            ->assertDontSee('private-test-token');

        Http::assertSent(function (Request $request) use ($a) {
            $filter = $request['variables']['filter'];

            return $request->url() === 'https://api.cloudflare.com/client/v4/graphql'
                && $request->hasHeader('Authorization', 'Bearer private-test-token')
                && $filter['requestHost'] === 'amanda-blog.com'
                && $filter['datetime_geq'] === '2026-09-19T16:00:00+00:00'
                && $filter['datetime_lt'] === '2026-09-21T04:00:00+00:00'
                && in_array('/article/'.$a->id, $filter['requestPath_in'], true)
                && ! in_array('/article/'.$a->id.'.md', $filter['requestPath_in'], true);
        });
    }

    public function test_cache_is_reused_and_invalidated_when_credentials_change(): void
    {
        $this->login();
        config()->set('cloudflare.cache_seconds', 300);
        Article::factory()->create();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->response())]);
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL)->assertOk();
        Http::assertSentCount(1);
        config()->set('cloudflare.api_token', 'replacement-token');
        $this->getJson(self::URL)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_disabling_cache_ignores_stored_results_and_fetches_each_time(): void
    {
        $this->login();
        $article = Article::factory()->create();
        $sequence = Http::sequence();
        foreach ([1, 2, 3] as $views) {
            $sequence->push($this->response([
                ['count' => $views, 'dimensions' => ['requestPath' => '/article/'.$article->id]],
            ]));
        }
        Http::fake(['api.cloudflare.com/*' => $sequence]);

        config()->set('cloudflare.cache_seconds', 300);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.summary.page_views', 1);
        config()->set('cloudflare.cache_seconds', 0);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.summary.page_views', 2)
            ->assertJsonPath('data.cache_seconds', 0)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.summary.page_views', 3);
        Http::assertSentCount(3);
    }

    public function test_empty_successful_data_is_distinct_from_provider_failure(): void
    {
        $this->login();
        Article::factory()->create();
        Http::fake(['api.cloudflare.com/*' => Http::response($this->response())]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.summary.page_views', 0)->assertJsonCount(2, 'data.daily');
    }

    #[DataProvider('failedResponses')]
    public function test_provider_failures_never_become_zero_counts_or_leak_responses(array $body, int $status): void
    {
        $this->login();
        Article::factory()->create();
        Http::fake(['api.cloudflare.com/*' => Http::response($body, $status)]);
        $this->getJson(self::URL)->assertStatus(503)->assertJsonPath('status', 'unavailable')
            ->assertJsonMissingPath('data')->assertDontSee('private-test-token');
        $this->getJson(self::URL)->assertStatus(503);
        Http::assertSentCount(2);
    }

    public static function failedResponses(): array
    {
        return [
            'graphql error with HTTP 200' => [['errors' => [['message' => 'private-test-token']]], 200],
            'forbidden' => [['message' => 'private-test-token'], 403],
            'upstream unavailable' => [[], 502],
            'malformed success' => [['data' => ['viewer' => ['accounts' => []]]], 200],
        ];
    }

    public function test_truncated_results_are_rejected(): void
    {
        $this->login();
        $article = Article::factory()->create();
        $rows = array_fill(0, 10000, ['count' => 1, 'dimensions' => ['requestPath' => '/article/'.$article->id]]);
        Http::fake(['api.cloudflare.com/*' => Http::response($this->response($rows))]);
        $this->getJson(self::URL)->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_date_range_validation_prevents_invalid_upstream_queries(): void
    {
        $this->login();
        Http::fake();
        foreach ([
            'start=2026-09-21&end=2026-09-20',
            'start=2026-09-20&end=2026-09-22',
            'start=2026-08-01&end=2026-09-21',
            'start=bad&end=2026-09-21',
        ] as $query) {
            $this->getJson('/api/admin/analytics/articles?'.$query)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }
}
