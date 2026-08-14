<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLineWebhookEvent;
use App\Services\LineMessagingService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class LineWebhookController extends Controller
{
    public function handle(
        Request $request,
        LineMessagingService $line,
    ): JsonResponse {
        $startedAt = hrtime(true);
        $receivedAtMs = $this->nowMs();
        $body = $request->getContent();

        if (! $line->isValidSignature($body, $request->header('x-line-signature'))) {
            $this->writeLog('warning', 'LINE webhook rejected.', [
                'reason' => 'invalid_signature',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $this->writeLog('warning', 'LINE webhook rejected.', [
                'reason' => 'invalid_payload',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        foreach ($payload['events'] ?? [] as $event) {
            if (($event['type'] ?? null) !== 'message'
                || ($event['message']['type'] ?? null) !== 'text'
                || ! isset($event['replyToken'], $event['message']['text'])) {
                continue;
            }

            $job = new ProcessLineWebhookEvent($event, $receivedAtMs);

            try {
                $this->writeLog('info', 'LINE webhook accepted.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                    'command' => $this->commandForLog((string) $event['message']['text']),
                    'event_timestamp_ms' => $event['timestamp'] ?? null,
                    'is_redelivery' => (bool) ($event['deliveryContext']['isRedelivery'] ?? false),
                    'received_at_ms' => $receivedAtMs,
                ]);

                dispatch($job);
            } catch (Throwable $exception) {
                $this->releaseUniqueLock($job);

                $this->writeLog('error', 'LINE webhook dispatch failed.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                    'type' => $exception::class,
                ]);

                // Returning a non-2xx response allows LINE webhook redelivery to
                // recover an event that could not be persisted to the queue.
                return response()->json(['message' => 'Unable to queue webhook event.'], 500);
            }
        }

        return response()->json([]);
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function commandForLog(string $message): string
    {
        $message = preg_replace('/^\p{Cf}+/u', '', trim($message)) ?? trim($message);

        return preg_match('/^[!！]/u', $message) === 1
            ? mb_substr($message, 0, 120)
            : '[non-command]';
    }

    private function releaseUniqueLock(ProcessLineWebhookEvent $job): void
    {
        try {
            (new UniqueLock(app(CacheRepository::class)))->release($job);
        } catch (Throwable $exception) {
            error_log(sprintf('Unable to release LINE webhook unique lock: %s', $exception->getMessage()));
        }
    }

    /**
     * Logging is diagnostic and must never prevent LINE from receiving a response.
     * PHP's error log remains available as a last-resort signal in container logs.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeLog(
        string $level,
        string $message,
        array $context = [],
    ): void {
        try {
            Log::channel('webhook')->log($level, $message, $context);
        } catch (Throwable $exception) {
            error_log(sprintf('Unable to write webhook log: %s', $exception->getMessage()));
        }
    }
}
