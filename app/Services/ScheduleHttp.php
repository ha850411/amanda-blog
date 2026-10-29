<?php

namespace App\Services;

use Fiber;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Cooperatively batch HTTP work without processes, extra workers or cached data. */
class ScheduleHttp
{
    /** @var array<int, true> */
    private static array $managedFibers = [];

    /**
     * Tasks run until they need HTTP, then their requests share a bounded pool.
     * Later rounds handle dependencies such as API fallbacks and short histories.
     * A failed task is returned as a Throwable without discarding its siblings.
     *
     * @param  array<string, callable>  $tasks
     * @return array<string, mixed|Throwable>
     */
    public static function run(array $tasks, int $concurrency = 5): array
    {
        $fibers = [];
        $waiting = [];
        $results = [];
        $advance = static function (string $key, callable $resume) use (&$fibers, &$waiting, &$results): void {
            try {
                $batch = $resume();
                if ($fibers[$key]->isTerminated()) {
                    $results[$key] = $fibers[$key]->getReturn();
                    unset($waiting[$key]);
                } else {
                    $waiting[$key] = $batch;
                }
            } catch (Throwable $exception) {
                $results[$key] = $exception;
                unset($waiting[$key]);
            }
        };

        try {
            foreach ($tasks as $key => $task) {
                $fibers[$key] = new Fiber($task);
                self::$managedFibers[spl_object_id($fibers[$key])] = true;
                $advance($key, fn () => $fibers[$key]->start());
            }

            while ($waiting !== []) {
                $batches = $waiting;
                $pools = [];
                $errors = [];
                try {
                    $responses = Http::pool(function (Pool $pool) use ($batches, &$pools, &$errors): void {
                        foreach ($batches as $key => $batch) {
                            $pools[$key] = new ScheduleHttpPool($pool, (string) count($pools));
                            try {
                                ($batch['callback'])($pools[$key]);
                            } catch (Throwable $exception) {
                                $errors[$key] = $exception;
                            }
                        }
                    }, concurrency: max(1, min($concurrency, ...array_column($batches, 'concurrency'))));
                } catch (Throwable $exception) {
                    $responses = [];
                    $errors = array_fill_keys(array_keys($batches), $exception);
                }

                foreach ($batches as $key => $batch) {
                    if (isset($errors[$key])) {
                        $advance($key, fn () => $fibers[$key]->throw($errors[$key]));

                        continue;
                    }
                    $taskResponses = [];
                    foreach ($pools[$key]->getRequests() as $requestKey => $request) {
                        $taskResponses[$requestKey] = $responses[$pools[$key]->responseKey($requestKey)];
                    }
                    $advance($key, fn () => $fibers[$key]->resume($taskResponses));
                }
            }
        } finally {
            foreach ($fibers as $fiber) {
                unset(self::$managedFibers[spl_object_id($fiber)]);
            }
        }

        // Preserve source order regardless of which tasks completed first.
        return array_replace(array_fill_keys(array_keys($tasks), null), $results);
    }

    public static function pool(callable $callback, int $concurrency = 5): array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null && isset(self::$managedFibers[spl_object_id($fiber)])) {
            return Fiber::suspend(compact('callback', 'concurrency'));
        }

        return Http::pool($callback, concurrency: $concurrency);
    }
}
