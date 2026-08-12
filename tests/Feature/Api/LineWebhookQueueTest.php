<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLineWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LineWebhookQueueTest extends TestCase
{
    public function test_valid_schedule_query_is_acknowledged_then_queued_without_running_schedule_requests(): void
    {
        config([
            'services.line.channel_secret' => 'test-secret',
            'services.line.channel_access_token' => 'test-token',
        ]);
        Queue::fake();
        Http::fake([
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $event = [
            'webhookEventId' => '01HWEBHOOK123',
            'type' => 'message',
            'replyToken' => 'reply-token',
            'timestamp' => 1786500000000,
            'source' => [
                'type' => 'group',
                'groupId' => 'C123456789',
                'userId' => 'U123456789',
            ],
            'message' => [
                'id' => 'message-123',
                'type' => 'text',
                'text' => '!lol 今天',
            ],
        ];
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $response = $this->withHeaders(['X-Line-Signature' => $signature])
            ->call('POST', '/api/line/webhook', [], [], [], [], $body);

        $response->assertOk()->assertExactJson([]);
        Queue::assertPushed(ProcessLineWebhookEvent::class, function (ProcessLineWebhookEvent $job) use ($event): bool {
            return $job->event === $event
                && $job->processingAcknowledged
                && $job->uniqueId() === '01HWEBHOOK123';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.line.me/v2/bot/message/reply'
                && $request['replyToken'] === 'reply-token'
                && $request['messages'] === [[
                    'type' => 'text',
                    'text' => '賽程查詢中，請稍候…',
                ]];
        });
        Http::assertSentCount(1);
    }
}
