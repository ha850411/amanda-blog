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
        $body = $request->getContent();

        if (! $line->isValidSignature($body, $request->header('x-line-signature'))) {
            $this->writeLog(Log::channel('webhook'), 'warning', 'LINE webhook rejected.', [
                'reason' => 'invalid_signature',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $this->writeLog(Log::channel('webhook'), 'warning', 'LINE webhook rejected.', [
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

            try {
                ProcessLineWebhookEvent::dispatch($event);
            } catch (Throwable $exception) {
                $this->writeLog(Log::channel('webhook'), 'error', 'LINE webhook dispatch failed.', [
                    'message_id' => $event['message']['id'] ?? null,
                    'type' => $exception::class,
                ]);
            }
        }

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
