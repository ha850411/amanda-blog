<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLineWebhookEvent;
use App\Services\LineMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class LineWebhookController extends Controller
{
    public function handle(
        Request $request,
        LineMessagingService $line,
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
            try {
                ProcessLineWebhookEvent::dispatch($event);

                $this->writeLog($webhookLog, 'info', 'LINE webhook event queued.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                ]);
            } catch (Throwable $exception) {
                $this->writeLog($webhookLog, 'error', 'LINE webhook queue dispatch failed.', [
                    'webhook_event_id' => $event['webhookEventId'] ?? null,
                    'message_id' => $event['message']['id'] ?? null,
                    'type' => $exception::class,
                ]);

                report($exception);
            }
        }

        $this->writeLog($webhookLog, 'info', 'LINE webhook acknowledged.', [
            'event_count' => count($events),
            'duration_ms' => $this->durationMs($startedAt),
        ]);

        return response()->json([]);
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
