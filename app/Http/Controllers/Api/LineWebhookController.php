<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLineWebhookEvent;
use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class LineWebhookController extends Controller
{
    public function handle(
        Request $request,
        LineMessagingService $line,
        LineScheduleBot $bot,
    ): JsonResponse {
        $startedAt = hrtime(true);
        $webhookLog = Log::channel('webhook');
        $body = $request->getContent();

        if (! $line->isValidSignature($body, $request->header('x-line-signature'))) {
            $this->writeLog($webhookLog, 'warning', 'LINE webhook rejected.', [
                'reason' => 'invalid_signature',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $this->writeLog($webhookLog, 'warning', 'LINE webhook rejected.', [
                'reason' => 'invalid_payload',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $events = array_values(array_filter($payload['events'] ?? [], 'is_array'));

        $this->writeLog($webhookLog, 'info', 'LINE webhook received.', [
            'event_count' => count($events),
        ]);

        foreach ($events as $event) {
            if (! $this->acceptOnce($event)) {
                $this->writeLog($webhookLog, 'info', 'LINE webhook duplicate ignored.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                ]);

                continue;
            }

            $processingAcknowledged = false;
            $target = $this->targetId($event);

            if ($target !== null && $this->isScheduleQuery($event, $bot)) {
                try {
                    $line->reply((string) $event['replyToken'], '賽程查詢中，請稍候…');
                    $processingAcknowledged = true;

                    $this->writeLog($webhookLog, 'info', 'LINE schedule query acknowledged.', [
                        'webhook_event_id' => $event['webhookEventId'] ?? null,
                        'message_id' => $event['message']['id'] ?? null,
                        'target_type' => $event['source']['type'] ?? null,
                    ]);
                } catch (Throwable $exception) {
                    $this->writeLog($webhookLog, 'warning', 'LINE schedule query acknowledgement failed.', [
                        'webhook_event_id' => $event['webhookEventId'] ?? null,
                        'message_id' => $event['message']['id'] ?? null,
                        'type' => $exception::class,
                    ]);
                }
            }

            try {
                ProcessLineWebhookEvent::dispatch($event, $processingAcknowledged);

                $this->writeLog($webhookLog, 'info', 'LINE webhook event queued.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                    'processing_acknowledged' => $processingAcknowledged,
                ]);
            } catch (Throwable $exception) {
                $this->releaseAcceptedEvent($event);

                $this->writeLog($webhookLog, 'error', 'LINE webhook queue dispatch failed.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                    'type' => $exception::class,
                ]);

                report($exception);

                if ($processingAcknowledged && $target !== null) {
                    try {
                        $line->push($target, '賽程查詢啟動失敗，請稍後再試。');
                    } catch (Throwable $pushException) {
                        report($pushException);
                    }
                }
            }
        }

        $this->writeLog($webhookLog, 'info', 'LINE webhook acknowledged.', [
            'event_count' => count($events),
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return response()->json([]);
    }

    /** @param array<string, mixed> $event */
    private function isScheduleQuery(array $event, LineScheduleBot $bot): bool
    {
        return ($event['type'] ?? null) === 'message'
            && ($event['message']['type'] ?? null) === 'text'
            && isset($event['replyToken'], $event['message']['text'])
            && $bot->isScheduleQuery((string) $event['message']['text']);
    }

    /** @param array<string, mixed> $event */
    private function targetId(array $event): ?string
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $target = match ($source['type'] ?? null) {
            'group' => $source['groupId'] ?? null,
            'room' => $source['roomId'] ?? null,
            'user' => $source['userId'] ?? null,
            default => null,
        };

        return is_string($target) && $target !== '' ? $target : null;
    }

    /** @param array<string, mixed> $event */
    private function acceptOnce(array $event): bool
    {
        $key = $this->acceptedEventCacheKey($event);

        if ($key === null) {
            return true;
        }

        try {
            return Cache::add($key, true, 300);
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    /** @param array<string, mixed> $event */
    private function releaseAcceptedEvent(array $event): void
    {
        $key = $this->acceptedEventCacheKey($event);

        if ($key === null) {
            return;
        }

        try {
            Cache::forget($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $event */
    private function acceptedEventCacheKey(array $event): ?string
    {
        $eventId = $event['webhookEventId'] ?? null;

        return is_string($eventId) && $eventId !== ''
            ? 'line-webhook:accepted:'.$eventId
            : null;
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Logging is diagnostic and must never prevent LINE from receiving a response.
     * PHP's error log remains available as a last-resort signal in container logs.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeLog(
        LoggerInterface $logger,
        string $level,
        string $message,
        array $context = [],
    ): void {
        try {
            $logger->log($level, $message, $context);
        } catch (Throwable $exception) {
            error_log(sprintf('Unable to write webhook log: %s', $exception->getMessage()));
        }
    }
}
