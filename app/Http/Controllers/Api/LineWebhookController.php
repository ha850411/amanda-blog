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
        $body = $request->getContent();

        if (! $line->isValidSignature($body, $request->header('x-line-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        foreach ($payload['events'] ?? [] as $event) {
            if (($event['type'] ?? null) !== 'message'
                || ($event['message']['type'] ?? null) !== 'text'
                || ! isset($event['replyToken'], $event['message']['text'])) {
                continue;
            }

            try {
                $line->reply(
                    (string) $event['replyToken'],
                    $bot->respond((string) $event['message']['text']),
                );
            } catch (Throwable $exception) {
                Log::error('Failed to handle LINE webhook event.', [
                    'exception' => $exception,
                    'message_id' => $event['message']['id'] ?? null,
                ]);
            }
        }

        return response()->json([]);
    }
}
