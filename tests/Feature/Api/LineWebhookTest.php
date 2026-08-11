<?php

namespace Tests\Feature\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.line.channel_secret' => 'test-secret',
            'services.line.channel_access_token' => 'test-token',
            'services.bo3.base_url' => 'https://bo3.gg',
            'services.bo3.timezone' => 'Asia/Taipei',
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 09:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_rejects_an_invalid_line_signature(): void
    {
        $response = $this->callWebhook('{"events":[]}', 'invalid');

        $response->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_line_can_query_tomorrow_lol_schedule(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => '123',
                    'type' => 'text',
                    'text' => 'lol 明天',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/lol/matches/current')
            && $request['date'] === '2026-08-12');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            $text = $request['messages'][0]['text'];

            return $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['replyToken'] === 'reply-token'
                && str_contains($text, 'LoL 08/12 賽程（台灣時間）')
                && str_contains($text, '07:00｜Team Alpha vs Team Beta')
                && ! str_contains($text, 'Previous Day Match');
        });
    }

    public function test_webhook_verification_with_no_events_returns_ok(): void
    {
        Http::fake();
        $body = '{"destination":"U123","events":[]}';

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    private function callWebhook(string $body, string $signature)
    {
        return $this->call(
            'POST',
            '/api/line/webhook',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $signature,
            ],
            content: $body,
        );
    }

    private function signature(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
    }

    private function bo3Html(): string
    {
        $events = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => 'Previous Day Match',
                'url' => 'https://bo3.gg/lol/matches/previous',
                'startDate' => '2026-08-11T12:00:00.000+00:00',
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => 'Team Alpha vs Team Beta',
                'url' => 'https://bo3.gg/lol/matches/alpha-vs-beta',
                'startDate' => '2026-08-11T23:00:00.000+00:00',
            ],
        ];

        return '<html><script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }
}
