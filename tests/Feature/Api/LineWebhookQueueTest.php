<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLineWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LineWebhookQueueTest extends TestCase
{
    public function test_valid_webhook_is_queued_without_running_external_requests(): void
    {
        config(['services.line.channel_secret' => 'test-secret']);
        Queue::fake();
        Http::fake();

        $event = [
            'webhookEventId' => '01HWEBHOOK123',
            'type' => 'message',
            'replyToken' => 'reply-token',
            'timestamp' => 1786500000000,
            'message' => [
                'id' => 'message-123',
                'type' => 'text',
                'text' => '!lol 今天',
            ],
        ];
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $response = $this->call(
            'POST',
            '/api/line/webhook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $signature,
            ],
            content: $body,
        );

        $response->assertOk()->assertExactJson([]);
        Queue::assertPushed(ProcessLineWebhookEvent::class, function (ProcessLineWebhookEvent $job) use ($event): bool {
            return $job->event === $event && $job->uniqueId() === '01HWEBHOOK123';
        });
        Http::assertNothingSent();
    }
}
