<?php

namespace Tests\Feature;

use App\Services\ScheduleHttp;
use Fiber;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ScheduleHttpTest extends TestCase
{
    public function test_independent_sources_start_before_any_source_is_awaited_and_keep_their_own_keys(): void
    {
        $events = [];
        $this->fakeDeferred(function (Request $request) use (&$events): Promise {
            $events[] = 'start:'.$request->url();
            $promise = new Promise(function () use (&$promise, &$events, $request): void {
                $events[] = 'finish:'.$request->url();
                $promise->resolve(new Response(200, [], json_encode(['url' => $request->url()])));
            });

            return $promise;
        });
        $tasks = [];
        foreach (['esports', 'mlb', 'nba'] as $source) {
            $tasks[$source] = fn (): string => ScheduleHttp::pool(function (Pool $pool) use ($source): void {
                $pool->as('schedule')->get('https://example.test/'.$source);
            })['schedule']->json('url');
        }

        $results = ScheduleHttp::run($tasks);

        $this->assertSame([
            'esports' => 'https://example.test/esports',
            'mlb' => 'https://example.test/mlb',
            'nba' => 'https://example.test/nba',
        ], $results);
        $this->assertSame([
            'start:https://example.test/esports',
            'start:https://example.test/mlb',
            'start:https://example.test/nba',
        ], array_slice($events, 0, 3));
    }

    public function test_concurrency_limit_is_shared_by_all_tasks_and_later_calls_still_fetch_fresh_data(): void
    {
        $active = 0;
        $peak = 0;
        $this->fakeDeferred(function () use (&$active, &$peak): Promise {
            $peak = max($peak, ++$active);
            $promise = new Promise(function () use (&$promise, &$active): void {
                $active--;
                $promise->resolve(new Response(200, [], '{}'));
            });

            return $promise;
        });
        $task = fn (): array => ScheduleHttp::pool(function (Pool $pool): void {
            foreach (range(1, 6) as $index) {
                $pool->as((string) $index)->get('https://example.test/'.$index);
            }
        });

        foreach (range(1, 2) as $invocation) {
            $results = ScheduleHttp::run(['history' => $task, 'odds' => $task]);
            $this->assertCount(6, $results['history']);
            $this->assertCount(6, $results['odds']);
            $this->assertSame(5, $peak);
            $this->assertSame(0, $active);
            Http::assertSentCount($invocation * 12);
        }
    }

    public function test_failure_and_dependent_rounds_do_not_discard_other_sources(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/failed' => Http::failedConnection(),
            'https://example.test/primary' => Http::response([], 503),
            'https://example.test/fallback' => Http::response(['result' => 'recovered']),
        ]);
        $results = ScheduleHttp::run([
            'throws' => function (): void {
                throw new RuntimeException('source unavailable');
            },
            'connection' => fn (): array => ScheduleHttp::pool(fn (Pool $pool) => $pool->as('request')->get('https://example.test/failed')),
            'recovery' => function (): string {
                $primary = ScheduleHttp::pool(fn (Pool $pool) => $pool->as('request')->get('https://example.test/primary'));
                $this->assertSame(503, $primary['request']->status());

                return ScheduleHttp::pool(fn (Pool $pool) => $pool->as('request')->get('https://example.test/fallback'))['request']->json('result');
            },
        ]);

        $this->assertInstanceOf(RuntimeException::class, $results['throws']);
        $this->assertInstanceOf(ConnectionException::class, $results['connection']['request']);
        $this->assertSame('recovered', $results['recovery']);
        $this->assertSame(['throws', 'connection', 'recovery'], array_keys($results));
        Http::assertSentCount(2); // Connection failures have no recorded response.
    }

    public function test_pool_callback_failure_is_delivered_to_its_task_and_scheduler_state_is_cleared(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $results = ScheduleHttp::run([
            'bad' => fn () => ScheduleHttp::pool(function (): void {
                throw new RuntimeException('bad request configuration');
            }),
            'good' => fn (): array => ScheduleHttp::pool(fn (Pool $pool) => $pool->get('https://example.test/good')),
        ]);
        $this->assertInstanceOf(RuntimeException::class, $results['bad']);
        $this->assertTrue($results['good'][0]->json('ok'));

        $fiber = new Fiber(fn (): array => ScheduleHttp::pool(fn (Pool $pool) => $pool->get('https://example.test/standalone')));
        $fiber->start();
        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($fiber->getReturn()[0]->json('ok'));
        Http::assertSentCount(2);
    }

    private function fakeDeferred(callable $callback): void
    {
        // Factory::fake waits for stubbed promises to gather transfer stats.
        // Install the raw handler so these tests can observe pending requests.
        $factory = new class extends Factory
        {
            public function defer(callable $callback): void
            {
                $this->stubCallbacks = collect([$callback]);
                $this->preventStrayRequests();
                $this->record();
            }
        };
        $factory->defer($callback);
        Http::swap($factory);
    }
}
