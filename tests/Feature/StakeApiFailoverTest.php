<?php

namespace Tests\Feature;

use App\Services\LineScheduleBot;
use App\Services\StakeBetService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StakeApiFailoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stake.sources_file' => 'config/stake_sources.json',
            'services.stake.access_token' => 'test-token',
            'services.stake.proxy' => 'http://pool-user-2:p%40ss@p.webshare.io:80',
            'services.stake.cache_seconds' => 0,
            'services.stake.max_retries' => 3,
            'services.stake.user_agent' => null,
        ]);
        Http::preventStrayRequests();
    }

    public function test_first_source_uses_direct_connection_and_stops_after_success(): void
    {
        config([
            'services.stake.cookie' => 'session=legacy-domain-cookie',
            'services.stake.kpsdk_cd' => 'legacy-domain-header',
        ]);
        Http::globalOptions(['proxy' => 'http://environment-proxy.invalid:8080']);
        Http::fake(function ($request, array $options) {
            $this->assertSame('https://stake.ac/_api/v1/graphql', $request->url());
            $this->assertSame('', $options['proxy']);
            $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
            $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(['test-token'], $request->header('x-access-token'));
            $this->assertSame(['https://stake.ac'], $request->header('Origin'));
            $this->assertSame(['https://stake.ac/'], $request->header('Referer'));
            $this->assertSame([], $request->header('Cookie'));
            $this->assertSame([], $request->header('x-kpsdk-cd'));
            $this->assertSame('ActiveBetCount_User', $request['operationName']);

            return Http::response($this->countResponse(3));
        });

        $this->assertSame(3, app(StakeBetService::class)->getActiveBetCount());
        Http::assertSentCount(1);
    }

    public function test_failed_source_is_skipped_by_later_queries_and_returns_to_priority_after_cooldown(): void
    {
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = $request->url();
            $this->assertSame('', $options['proxy']);
            if (count($seen) === 1) {
                return Http::response('<html>challenge</html>', 403, ['cf-mitigated' => 'challenge']);
            }

            return Http::response($this->countResponse(0));
        });

        $service = app(StakeBetService::class);
        $this->assertSame(0, $service->getActiveBetCount());
        $this->assertSame(0, app(StakeBetService::class)->getActiveBetCount());
        $this->travel(61)->seconds();
        $this->assertSame(0, $service->getActiveBetCount());
        $this->assertSame([
            'https://stake.ac/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
            'https://stake.ac/_api/v1/graphql',
        ], $seen);
    }

    public function test_active_bet_reply_loads_count_bets_and_balance_in_one_request(): void
    {
        Http::fake(['*' => Http::response($this->overviewResponse())]);

        $reply = app(LineScheduleBot::class)->reply('!bet text');

        $this->assertStringContainsString('進行中注單（1 筆）', $reply->text);
        $this->assertStringContainsString('可用：12.5 USDT', $reply->text);
        $this->assertStringContainsString('投注：10 USDT', $reply->text);
        $this->assertNull($reply->imageData);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['operationName'] === 'ActiveBetsOverview'
            && $request['variables']['limit'] === 20);
    }

    public function test_zero_active_bet_reply_does_not_fetch_balance_again(): void
    {
        $response = $this->overviewResponse();
        $response['data']['user']['activeSportBetCount'] = 0;
        $response['data']['user']['activeSportBets'] = [];
        Http::fake(['*' => Http::response($response)]);

        $reply = app(LineScheduleBot::class)->reply('!bet 3');

        $this->assertStringContainsString('目前無進行中的 Stake 體育注單', $reply->text);
        $this->assertStringContainsString('可用：12.5 USDT', $reply->text);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['variables']['limit'] === 3);
    }

    public function test_incomplete_overview_fails_over_instead_of_returning_partial_account_data(): void
    {
        $response = $this->overviewResponse();
        $partial = $response;
        unset($partial['data']['user']['balances']);
        Http::fake(['*' => Http::sequence()->push($partial)->push($response)]);

        $overview = app(StakeBetService::class)->getActiveBetsOverview(3);

        $this->assertSame(1, $overview['count']);
        $this->assertCount(1, $overview['bets']);
        $this->assertSame(16.0, $overview['balance']['total']);
        Http::assertSentCount(2);
    }

    public function test_source_cooldown_is_shared_between_operations_without_caching_account_data(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();
            if (count($seen) === 1) {
                return Http::response('challenge', 403, ['cf-mitigated' => 'challenge']);
            }

            return $request['operationName'] === 'UserBalances'
                ? Http::response($this->overviewResponse())
                : Http::response($this->countResponse(count($seen)));
        });

        $service = app(StakeBetService::class);
        $this->assertSame(2, $service->getActiveBetCount());
        $this->assertSame(16.0, $service->getUsdtBalance()['total']);
        $this->assertSame(4, $service->getActiveBetCount());
        $this->assertSame([
            'https://stake.ac/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
        ], $seen);
    }

    public function test_cache_outage_preserves_source_failover(): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Cache unavailable'));
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Cache unavailable'));
        Http::fake(['*' => Http::sequence()
            ->push('challenge', 403, ['cf-mitigated' => 'challenge'])
            ->push($this->countResponse(2))]);

        $this->assertSame(2, app(StakeBetService::class)->getActiveBetCount());
        Http::assertSentCount(2);
    }

    public function test_proxy_eight_is_used_only_after_all_five_direct_sources_fail(): void
    {
        Log::spy();
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = ['url' => $request->url(), 'proxy' => $options['proxy']];
            if (count($seen) <= 5) {
                $this->assertSame('', $options['proxy']);
            } else {
                $this->assertSame('http://pool-user-8:p%40ss@p.webshare.io:80', $options['proxy']);
                $this->assertSame('', $options['curl'][CURLOPT_NOPROXY]);
            }

            return match (count($seen)) {
                1 => throw new ConnectionException('Connection failed at http://pool-user-2:p%40ss@p.webshare.io:80'),
                2 => Http::response('challenge', 403, ['cf-mitigated' => 'challenge']),
                3 => Http::response('<html>verification</html>', 200, ['content-type' => 'text/html']),
                4 => Http::response([], 451),
                5 => Http::response([], 503),
                default => Http::response($this->countResponse(2)),
            };
        });

        $this->assertSame(2, app(StakeBetService::class)->getActiveBetCount());
        $this->assertSame([
            'https://stake.ac/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
            'https://staketr.com/_api/v1/graphql',
            'https://stkmirror.com/_api/v1/graphql',
            'https://stake1017.com/_api/v1/graphql',
            'https://stake.ac/_api/v1/graphql',
        ], array_column($seen, 'url'));
        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []): bool {
            return ! str_contains(json_encode([$message, $context]), 'p%40ss');
        })->times(5);
    }

    #[DataProvider('unusableResponses')]
    public function test_invalid_api_response_does_not_become_a_zero_bet_result(mixed $body, int $status, array $headers): void
    {
        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen, $body, $status, $headers) {
            $seen[] = $request->url();
            $this->assertFalse($options['allow_redirects']);

            return count($seen) === 1
                ? Http::response($body, $status, $headers)
                : Http::response($this->countResponse(4));
        });

        $this->assertSame(4, app(StakeBetService::class)->getActiveBetCount());
        $this->assertSame([
            'https://stake.ac/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
        ], $seen);
    }

    public static function unusableResponses(): array
    {
        return [
            'HTML with HTTP 200' => ['<html>Client Challenge</html>', 200, ['content-type' => 'text/html']],
            'GraphQL errors' => [['errors' => [['message' => 'Access unavailable']]], 200, []],
            'null account' => [['data' => ['user' => null]], 200, []],
            'missing requested field' => [['data' => ['user' => ['id' => 'test-user']]], 200, []],
            'challenge header with JSON' => [['data' => ['user' => ['activeSportBetCount' => 3]]], 200, ['cf-mitigated' => 'challenge']],
            'redirect to another host' => ['', 302, ['location' => 'https://unrelated.invalid/']],
        ];
    }

    public function test_invalid_token_stops_without_trying_every_source(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

        try {
            app(StakeBetService::class)->getActiveBetCount();
            $this->fail('Expected an authentication failure.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_all_sources_fail_once_and_bot_returns_verification_error(): void
    {
        Http::fake(['*' => Http::response('challenge', 403, ['cf-mitigated' => 'challenge'])]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertStringContainsString('瀏覽器安全驗證', $reply->text);
        Http::assertSentCount(6);
    }

    public function test_all_sources_in_cooldown_fail_fast_and_are_retried_after_expiry(): void
    {
        Http::fake(['*' => Http::response('challenge', 403, ['cf-mitigated' => 'challenge'])]);
        $bot = app(LineScheduleBot::class);

        $this->assertStringContainsString('瀏覽器安全驗證', $bot->reply('!bet')->text);
        $this->assertStringContainsString('無法連線至 Stake 查詢來源', $bot->reply('!bet')->text);
        Http::assertSentCount(6);

        $this->travel(61)->seconds();
        $this->assertStringContainsString('瀏覽器安全驗證', $bot->reply('!bet')->text);
        Http::assertSentCount(12);
    }

    public function test_missing_proxy_credentials_skips_backup_without_repeating_direct_source(): void
    {
        config(['services.stake.proxy' => null]);
        Http::fake(['*' => Http::response('challenge', 403, ['cf-mitigated' => 'challenge'])]);

        $reply = app(LineScheduleBot::class)->reply('!bet');

        $this->assertStringContainsString('瀏覽器安全驗證', $reply->text);
        Http::assertSentCount(5);
    }

    public function test_failed_graphql_response_does_not_expose_account_data_in_exception_message(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['user' => ['activeSportBetCount' => 3]],
            'errors' => [['message' => 'private-account-value']],
        ])]);

        try {
            app(StakeBetService::class)->getActiveBetCount();
            $this->fail('Expected the partial GraphQL response to fail.');
        } catch (RequestException $exception) {
            $this->assertStringNotContainsString('private-account-value', $exception->getMessage());
            $this->assertSame('private-account-value', $exception->response->json('errors.0.message'));
        }

        Http::assertSentCount(6);
    }

    public function test_balance_queries_also_fail_over_and_return_parsed_amounts(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();
            $this->assertSame('UserBalances', $request['operationName']);

            return count($seen) === 1
                ? Http::response(['data' => ['user' => null]])
                : Http::response(['data' => ['user' => ['balances' => [[
                    'available' => ['currency' => 'usdt', 'amount' => 12.5],
                    'vault' => ['currency' => 'usdt', 'amount' => 3.5],
                ]]]]]);
        });

        $this->assertSame(['available' => 12.5, 'vault' => 3.5, 'active' => 0.0, 'total' => 16.0], app(StakeBetService::class)->getUsdtBalance());
        $this->assertSame([
            'https://stake.ac/_api/v1/graphql',
            'https://stake.mba/_api/v1/graphql',
        ], $seen);
    }

    public function test_malformed_source_configuration_does_not_send_a_request(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stake-sources-');
        file_put_contents($path, '{invalid JSON');
        config(['services.stake.sources_file' => $path]);
        Http::fake();

        try {
            app(StakeBetService::class)->getActiveBetCount();
            $this->fail('Expected invalid source configuration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stake source configuration must be valid JSON.', $exception->getMessage());
        } finally {
            unlink($path);
        }

        Http::assertNothingSent();
    }

    private function countResponse(int $count): array
    {
        return ['data' => ['user' => ['activeSportBetCount' => $count]]];
    }

    private function overviewResponse(): array
    {
        return ['data' => ['user' => [
            'activeSportBetCount' => 1,
            'activeSportBets' => [[
                'id' => 'test-bet',
                'active' => true,
                'status' => 'confirmed',
                'currency' => 'usdt',
                'amount' => 10,
                'potentialMultiplier' => 2,
                'outcomes' => [],
            ]],
            'balances' => [[
                'available' => ['currency' => 'usdt', 'amount' => 12.5],
                'vault' => ['currency' => 'usdt', 'amount' => 3.5],
            ]],
        ]]];
    }
}
