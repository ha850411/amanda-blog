<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLineWebhookEvent;
use App\Services\LineBotReply;
use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProcessLineWebhookEventTest extends TestCase
{
    public function test_acknowledged_group_query_pushes_completed_result_to_the_original_chat(): void
    {
        config([
            'services.line.channel_access_token' => 'test-token',
            'services.line.push_url' => 'https://api.line.me/v2/bot/message/push',
        ]);

        Http::fake([
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $bot = Mockery::mock(LineScheduleBot::class);
        $bot->shouldReceive('reply')
            ->once()
            ->with('!lol 今天')
            ->andReturn(new LineBotReply('完整賽程內容'));

        $images = Mockery::mock(LineScheduleImageService::class);
        $images->shouldNotReceive('create');

        $event = [
            'webhookEventId' => '01HWEBHOOK123',
            'type' => 'message',
            'replyToken' => 'already-used-reply-token',
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

        $job = new ProcessLineWebhookEvent($event, true);
        $job->handle(
            app(LineMessagingService::class),
            $bot,
            $images,
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request['to'] === 'C123456789'
                && $request['messages'] === [[
                    'type' => 'text',
                    'text' => '完整賽程內容',
                ]];
        });
        Http::assertSentCount(1);
    }
}
