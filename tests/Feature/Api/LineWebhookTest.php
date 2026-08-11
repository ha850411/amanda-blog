<?php

namespace Tests\Feature\Api;

use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
            'services.odds.api_key' => null,
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.odds.bookmakers' => 'Pinnacle,Bet365',
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
        $this->fakeScheduleImage();

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
                    'text' => '!lol 明天',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/lol/matches/current')
            && $request['date'] === '2026-08-12'
            && $request['tiers'] === 's,a');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            $messages = $request['messages'];

            return $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['replyToken'] === 'reply-token'
                && count($messages) === 2
                && $messages[0] === [
                    'type' => 'image',
                    'originalContentUrl' => 'https://cdn.example.com/line-schedules/test/1040',
                    'previewImageUrl' => 'https://cdn.example.com/line-schedules/test/700',
                ]
                && $messages[1] === [
                    'type' => 'text',
                    'text' => '完整賽程｜https://bo3.gg/lol/matches/current?tiers=s,a&date=2026-08-12',
                ];
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

    public function test_help_command_returns_usage_instructions(): void
    {
        Http::fake([
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'help-test',
                    'type' => 'text',
                    'text' => '!help',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            return $request['messages'][0] === [
                'type' => 'text',
                'text' => "指令格式：\n!lol 今天\n!val 明天\n!cs 08/11\n\n預設查 S/A Tier。\n可選參數：tier=s,a｜tier=all｜limit=5｜team=G2",
            ];
        });
    }

    public function test_non_command_message_is_ignored(): void
    {
        Http::fake();

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'chat-test',
                    'type' => 'text',
                    'text' => '今天有什麼比賽？',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function test_invalid_command_is_ignored(): void
    {
        Http::fake();

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'invalid-command-test',
                    'type' => 'text',
                    'text' => '!lol 下週',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function test_image_failure_falls_back_to_text_with_one_filtered_link(): void
    {
        $images = Mockery::mock(LineScheduleImageService::class);
        $images->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('Image storage is unavailable'));
        $this->app->instance(LineScheduleImageService::class, $images);

        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'fallback-test',
                    'type' => 'text',
                    'text' => '!lol 明天',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            $text = $request['messages'][0]['text'] ?? '';

            return $request['messages'][0]['type'] === 'text'
                && str_contains($text, '完整賽程｜https://bo3.gg/lol/matches/current?tiers=s,a&date=2026-08-12')
                && substr_count($text, 'https://bo3.gg/') === 1
                && ! str_contains($text, '/lol/matches/alpha-vs-beta');
        });
    }

    public function test_logging_failure_does_not_prevent_a_line_reply(): void
    {
        $this->fakeScheduleImage();

        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')
            ->twice()
            ->andThrow(new RuntimeException('Permission denied'));
        Log::shouldReceive('channel')
            ->once()
            ->with('webhook')
            ->andReturn($logger);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => '456',
                    'type' => 'text',
                    'text' => '!lol 08/11',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.line.me/v2/bot/message/reply'
            && $request['messages'][0]['type'] === 'image'
            && $request['messages'][0]['originalContentUrl'] === 'https://cdn.example.com/line-schedules/test/1040'
            && $request['messages'][1] === [
                'type' => 'text',
                'text' => '完整賽程｜https://bo3.gg/lol/matches/current?tiers=s,a&period',
            ]);
    }

    public function test_log_viewer_requires_admin_authentication(): void
    {
        $this->get('/log-viewer')
            ->assertRedirect(route('admin.login'));
    }

    public function test_today_and_default_tiers_are_supported(): void
    {
        Http::fake([
            'https://bo3.gg/matches/current*' => Http::response($this->bo3Html(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->respond('!cs 今天');

        $this->assertStringContainsString('CS2｜08/11｜S/A Tier', $reply);
        $this->assertStringContainsString('Previous Day Match', $reply);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/matches/current?tiers=s,a&period', $reply);
        $this->assertSame(1, substr_count($reply, 'https://bo3.gg/'));

        Http::assertSent(fn ($request): bool => $request['date'] === '2026-08-11'
            && $request['tiers'] === 's,a');
    }

    public function test_optional_tier_limit_and_team_parameters_are_supported(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->respond('!val 明天 tier=all limit=1 team="Team Alpha"');

        $this->assertStringContainsString('VALORANT｜08/12｜全部 Tier', $reply);
        $this->assertStringContainsString("Team Alpha\nvs\nTeam Beta", $reply);
        $this->assertStringNotContainsString('Previous Day Match', $reply);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/valorant/matches/current?date=2026-08-12', $reply);
        $this->assertStringNotContainsString('/lol/matches/alpha-vs-beta', $reply);

        Http::assertSent(fn ($request): bool => $request['date'] === '2026-08-12'
            && ! isset($request['tiers']));
    }

    public function test_tournament_and_preferred_bookmaker_odds_are_included(): void
    {
        config([
            'services.odds.api_key' => 'odds-test-key',
            'services.odds.bookmakers' => null,
        ]);

        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.odds-api.io/v3/events*' => Http::response([[
                'id' => 456,
                'home' => 'Team Alpha',
                'away' => 'Team Beta',
                'date' => '2026-08-11T23:00:00Z',
                'league' => ['name' => 'League of Legends'],
                'sport' => ['name' => 'Esports'],
                'status' => 'pending',
            ]], 200),
            'https://api.odds-api.io/v3/bookmakers/selected*' => Http::response([
                'bookmakers' => ['Stake', 'Bet365'],
                'count' => 2,
            ], 200),
            'https://api.odds-api.io/v3/odds/multi*' => Http::response([[
                'id' => 456,
                'home' => 'Team Alpha',
                'away' => 'Team Beta',
                'bookmakers' => [
                    'Stake' => [[
                        'name' => 'ML',
                        'odds' => [['home' => '1.55', 'away' => '2.40']],
                    ]],
                    'Bet365' => [[
                        'name' => 'ML',
                        'odds' => [['home' => '1.60', 'away' => '2.30']],
                    ]],
                ],
            ]], 200),
        ]);

        $reply = app(LineScheduleBot::class)->respond('!lol 明天 team="Team Alpha"');

        $this->assertStringContainsString('賽事｜LCK 2026 Summer', $reply);
        $this->assertStringContainsString('Team Alpha　1.55（Stake）', $reply);
        $this->assertStringContainsString('Team Beta　2.40（Stake）', $reply);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/events')
            && $request['sport'] === 'esports'
            && $request['apiKey'] === 'odds-test-key');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/odds/multi')
            && $request['eventIds'] === '456'
            && $request['bookmakers'] === 'Stake,Bet365');
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

    private function fakeScheduleImage(): void
    {
        $images = Mockery::mock(LineScheduleImageService::class);
        $images->shouldReceive('create')
            ->once()
            ->andReturn('https://cdn.example.com/line-schedules/test');
        $this->app->instance(LineScheduleImageService::class, $images);
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

        return '<html><div class="table-row table-row--upcoming">'
            .'<a href="/lol/matches/previous">Previous Team Bo5 Another Team</a><p class="tournament-name">KeSPA Cup 2026</p></div>'
            .'<div class="table-row table-row--upcoming">'
            .'<a href="/lol/matches/alpha-vs-beta">Team AlphaBo3Team Beta</a><p class="tournament-name">LCK 2026 Summer</p></div>'
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }
}
