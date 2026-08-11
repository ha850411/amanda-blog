<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            $webhookLog->warning('LINE webhook rejected.', [
                'reason' => 'invalid_signature',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            $webhookLog->warning('LINE webhook rejected.', [
                'reason' => 'invalid_payload',
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $webhookLog->info('LINE webhook received.', [
            'event_count' => count($payload['events'] ?? []),
        ]);

        foreach ($payload['events'] ?? [] as $event) {
            if (($event['type'] ?? null) !== 'message'
                || ($event['message']['type'] ?? null) !== 'text'
                || ! isset($event['replyToken'], $event['message']['text'])) {
                continue;
            }

            try {
                $eventStartedAt = hrtime(true);
                $message = (string) $event['message']['text'];
                $command = str_starts_with(trim($message), '!') ? mb_substr(trim($message), 0, 120) : '[non-command]';

                $line->reply(
                    (string) $event['replyToken'],
                    $bot->respond($message),
                );

                $webhookLog->info('LINE webhook replied.', [
                    'message_id' => $event['message']['id'] ?? null,
                    'command' => $command,
                    'duration_ms' => $this->durationMs($eventStartedAt),
                ]);
            } catch (Throwable $exception) {
                $webhookLog->error('LINE webhook failed.', [
                    'message_id' => $event['message']['id'] ?? null,
                    'type' => $exception::class,
                    'duration_ms' => isset($eventStartedAt) ? $this->durationMs($eventStartedAt) : null,
                ]);

                Log::error('Failed to handle LINE webhook event.', [
                    'exception' => $exception,
                    'message_id' => $event['message']['id'] ?? null,
                ]);
            }
        }

        return response()->json([]);
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
