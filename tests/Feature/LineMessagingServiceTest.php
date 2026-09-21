<?php

namespace Tests\Feature;

use App\Services\LineMessagingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineMessagingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.channel_access_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://api.line.me/*' => Http::response(['sentMessages' => []])]);
    }

    public function test_long_history_text_preserves_every_row_and_the_final_link_in_one_request(): void
    {
        $text = str_repeat("・2026/09/20 BO3｜勝 2：0 vs Bilibili Gaming\n", 240).'完整賽程｜https://bo3.gg/matches/current';
        app(LineMessagingService::class)->reply('reply-token', $text);
        Http::assertSent(function (Request $request) use ($text): bool {
            $messages = $request['messages'];
            $this->assertGreaterThan(1, count($messages));
            $this->assertLessThanOrEqual(5, count($messages));
            $this->assertSame($text, implode('', array_column($messages, 'text')));
            foreach (array_slice($messages, 0, -1) as $message) {
                $this->assertStringEndsWith("\n", $message['text']);
            }

            return true;
        });
        Http::assertSentCount(1);
    }

    public function test_text_with_emoji_respects_utf16_limits_without_splitting_surrogates(): void
    {
        $text = str_repeat('勝', 4999).'⚾'.str_repeat('🏆', 2501).'最後一場';
        app(LineMessagingService::class)->push('test-user', $text);
        Http::assertSent(function (Request $request) use ($text): bool {
            $messages = $request['messages'];
            $this->assertSame($text, implode('', array_column($messages, 'text')));
            foreach ($messages as $message) {
                $this->assertTrue(mb_check_encoding($message['text'], 'UTF-8'));
                $this->assertLessThanOrEqual(10000, strlen(mb_convert_encoding($message['text'], 'UTF-16LE', 'UTF-8')));
            }

            return true;
        });
    }

    public function test_overlong_text_stays_within_five_messages_and_marks_omitted_content(): void
    {
        app(LineMessagingService::class)->reply('reply-token', str_repeat('🏆', 15000));
        Http::assertSent(function (Request $request): bool {
            $this->assertCount(5, $request['messages']);
            $last = $request['messages'][4]['text'];
            $this->assertStringContainsString('部分內容省略', $last);
            $this->assertLessThanOrEqual(10000, strlen(mb_convert_encoding($last, 'UTF-16LE', 'UTF-8')));

            return true;
        });
    }
}
