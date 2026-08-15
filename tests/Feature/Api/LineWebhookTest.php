<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessLineWebhookEvent;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
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
            'services.bo3.api_url' => 'https://api.bo3.gg/api/v1',
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
            && $request['tiers'] === 's');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            $messages = $request['messages'];

            return $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['replyToken'] === 'reply-token'
                && count($messages) === 1
                && $messages[0] === [
                    'type' => 'image',
                    'originalContentUrl' => 'https://cdn.example.com/line-schedules/test/1440',
                    'previewImageUrl' => 'https://cdn.example.com/line-schedules/test/700',
                ];
        });
    }

    public function test_lol_schedule_reply_includes_head_to_head_api_summary(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches/alpha-vs-beta' => Http::response([
                'team1_id' => 10,
                'team2_id' => 20,
                'discipline_id' => 3,
            ]),
            'https://api.bo3.gg/api/v1/matches*' => Http::response([
                'total' => ['count' => 9],
                'results' => [
                    ['team1_id' => 20, 'team2_id' => 10, 'team1_score' => 0, 'team2_score' => 2, 'start_date' => '2026-08-01T12:00:00+00:00', 'bo_type' => 3],
                    ['team1_id' => 10, 'team2_id' => 20, 'team1_score' => 1, 'team2_score' => 2, 'start_date' => '2026-07-01T12:00:00+00:00', 'bo_type' => 3],
                    ['team1_id' => 10, 'team2_id' => 20, 'team1_score' => 2, 'team2_score' => 0, 'start_date' => '2026-06-01T12:00:00+00:00', 'bo_type' => 3],
                ],
            ]),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!lol 明天');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('近期交手｜Team Alpha 2 勝・Team Beta 1 勝（近 3 場，小局 5：2）', $reply->text);
        $this->assertStringContainsString('交手明細｜', $reply->text);
        $this->assertStringContainsString('・2026/08/01 BO3  2：0（Team Alpha 勝）', $reply->text);
        $this->assertStringContainsString('・2026/07/01 BO3  1：2（Team Beta 勝）', $reply->text);
        $this->assertStringContainsString('・2026/06/01 BO3  2：0（Team Alpha 勝）', $reply->text);
        $this->assertSame([
            'sample_size' => 3,
            'history_total' => 9,
            'team1_wins' => 2,
            'team2_wins' => 1,
            'team1_games' => 5,
            'team2_games' => 2,
            'series' => [
                ['date' => '2026/08/01', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
                ['date' => '2026/07/01', 'format' => 'BO3', 'team1_score' => 1, 'team2_score' => 2, 'winner' => 'team2'],
                ['date' => '2026/06/01', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
            ],
        ], $reply->imageData['matches'][0]['h2h']);
    }

    public function test_cs_schedule_reply_includes_head_to_head_api_summary(): void
    {
        Http::fake([
            'https://bo3.gg/matches/current*' => Http::response($this->todayHtmlWithStartedAndUpcomingMatches(), 200),
            'https://api.bo3.gg/api/v1/matches/future-team-vs-next-team' => Http::response([
                'team1_id' => 30,
                'team2_id' => 40,
                'discipline_id' => 1,
            ]),
            'https://api.bo3.gg/api/v1/matches*' => Http::response([
                'total' => ['count' => 2],
                'results' => [
                    ['team1_id' => 30, 'team2_id' => 40, 'team1_score' => 2, 'team2_score' => 1, 'start_date' => '2026-05-01T12:00:00+00:00', 'bo_type' => 3],
                    ['team1_id' => 40, 'team2_id' => 30, 'team1_score' => 2, 'team2_score' => 0, 'start_date' => '2026-03-01T12:00:00+00:00', 'bo_type' => 3],
                ],
            ]),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!cs 08/11');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('近期交手｜Future Team 1 勝・Next Team 1 勝（近 2 場，小局 2：3）', $reply->text);
        $futureMatch = collect($reply->imageData['matches'])
            ->firstWhere('team1', 'Future Team');
        $this->assertNotNull($futureMatch);
        $this->assertSame(2, $futureMatch['h2h']['sample_size']);
        $this->assertSame(1, $futureMatch['h2h']['team1_wins']);
        $this->assertSame(1, $futureMatch['h2h']['team2_wins']);
        $this->assertSame(2, $futureMatch['h2h']['team1_games']);
        $this->assertSame(3, $futureMatch['h2h']['team2_games']);
    }

    public function test_webhook_verification_with_no_events_returns_ok(): void
    {
        Http::fake();
        $body = '{"destination":"U123","events":[]}';

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function test_webhook_queues_event_processing_before_external_api_calls(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        Http::fake();

        $body = json_encode([
            'events' => [[
                'webhookEventId' => 'queue-test-event',
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'queue-test-message',
                    'type' => 'text',
                    'text' => '!lol 今天 tier=s',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Bus::assertDispatched(ProcessLineWebhookEvent::class, function (ProcessLineWebhookEvent $job): bool {
            return $job->event['webhookEventId'] === 'queue-test-event'
                && is_int($job->webhookReceivedAtMs)
                && $job->webhookReceivedAtMs > 0
                && $job->event['message']['text'] === '!lol 今天 tier=s';
        });
        Http::assertNothingSent();
    }

    public function test_webhook_returns_an_error_when_the_event_cannot_be_queued(): void
    {
        config(['queue.default' => 'missing-connection']);
        Http::fake();

        $body = json_encode([
            'events' => [[
                'webhookEventId' => 'dispatch-failure-event',
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => 'dispatch-failure-message',
                    'type' => 'text',
                    'text' => '!help',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertStatus(500)->assertExactJson([
            'message' => 'Unable to queue webhook event.',
        ]);

        config(['queue.default' => 'sync']);
        Bus::fake();

        $redeliveryResponse = $this->callWebhook($body, $this->signature($body));

        $redeliveryResponse->assertOk()->assertExactJson([]);
        Bus::assertDispatched(ProcessLineWebhookEvent::class, fn (ProcessLineWebhookEvent $job): bool => $job->event['webhookEventId'] === 'dispatch-failure-event');
        Http::assertNothingSent();
    }

    public function test_an_event_that_waited_too_long_uses_push_without_trying_reply(): void
    {
        config(['services.line.reply_token_safe_window_seconds' => 45]);
        $this->fakeScheduleImage();

        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.line.me/v2/bot/message/push' => Http::response(
                ['sentMessages' => []],
                200,
                ['x-line-request-id' => 'push-request-id'],
            ),
        ]);

        $job = new ProcessLineWebhookEvent([
            'webhookEventId' => 'cold-query-event',
            'type' => 'message',
            'replyToken' => 'expired-before-delivery',
            'source' => [
                'type' => 'user',
                'userId' => 'U123',
            ],
            'message' => [
                'id' => 'cold-query-message',
                'type' => 'text',
                'text' => '!val 08/11',
            ],
        ], (int) floor(microtime(true) * 1000) - 46_000);

        $this->app->call([$job, 'handle']);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.line.me/v2/bot/message/reply');
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/push') {
                return false;
            }

            return $request['to'] === 'U123'
                && count($request['messages']) === 1
                && $request['messages'][0] === [
                    'type' => 'image',
                    'originalContentUrl' => 'https://cdn.example.com/line-schedules/test/1440',
                    'previewImageUrl' => 'https://cdn.example.com/line-schedules/test/700',
                ];
        });
    }

    public function test_val_today_falls_back_to_push_when_the_reply_token_is_unusable(): void
    {
        $this->fakeScheduleImage();

        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.line.me/v2/bot/message/reply' => Http::response([
                'message' => 'Invalid reply token',
            ], 400),
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $body = json_encode([
            'events' => [[
                'webhookEventId' => 'val-today-fallback-event',
                'type' => 'message',
                'replyToken' => 'expired-reply-token',
                'source' => [
                    'type' => 'user',
                    'userId' => 'U123',
                ],
                'message' => [
                    'id' => 'val-today-fallback-message',
                    'type' => 'text',
                    'text' => '!val 今天',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.line.me/v2/bot/message/reply'
            && $request['replyToken'] === 'expired-reply-token');
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/push') {
                return false;
            }

            return $request['to'] === 'U123'
                && $request['messages'][0]['type'] === 'text'
                && str_contains($request['messages'][0]['text'], 'VALORANT｜08/11｜S Tier');
        });
    }

    public function test_delivery_failure_is_rethrown_for_the_queue_to_retry(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/reply' => Http::response([], 503),
            'https://api.line.me/v2/bot/message/push' => Http::response([], 503),
        ]);

        $job = new ProcessLineWebhookEvent([
            'type' => 'message',
            'replyToken' => 'reply-token',
            'source' => [
                'type' => 'user',
                'userId' => 'U123',
            ],
            'message' => [
                'id' => 'retryable-delivery-message',
                'type' => 'text',
                'text' => '!help',
            ],
        ]);

        $this->expectException(RequestException::class);

        $this->app->call([$job, 'handle']);
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
                'text' => "指令格式：\n!match｜!lol｜!val｜!cs（未填日期預設今天）\n!賽程 08/15 game=lol/val/cs\n!lol 今天｜!val 明天｜!cs 08/11\n\n查今天顯示滾球中和尚未開打的賽事，預設查 S Tier。\n可選參數：game=lol/val/cs｜tier=s,a｜tier=all｜limit=5｜team=G2",
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
                && str_contains($text, '完整賽程｜https://bo3.gg/lol/matches/current?tiers=s&date=2026-08-12')
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
            ->times(3)
            ->andThrow(new RuntimeException('Permission denied'));
        Log::shouldReceive('channel')
            ->twice()
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
            && count($request['messages']) === 1
            && $request['messages'][0]['type'] === 'image'
            && $request['messages'][0]['originalContentUrl'] === 'https://cdn.example.com/line-schedules/test/1440');
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

        $this->assertStringContainsString('CS2｜08/11｜S Tier', $reply);
        $this->assertStringContainsString('Previous Day Match', $reply);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/matches/current?tiers=s&period', $reply);
        $this->assertSame(1, substr_count($reply, 'https://bo3.gg/'));

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/matches/current')
            && $request['date'] === '2026-08-11'
            && $request['tiers'] === 's');
    }

    public function test_visually_equivalent_unicode_commands_are_normalized(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
        ]);

        $messages = [
            "\u{200B}!lol 08/13 tier=s",
            '！ｌｏｌ　０８／１３　ｔｉｅｒ＝ｓ',
        ];

        foreach ($messages as $message) {
            $reply = app(LineScheduleBot::class)->respond($message);

            $this->assertNotNull($reply);
            $this->assertStringContainsString('LoL 08/13 查無賽程。', $reply);
        }
    }

    public function test_optional_tier_limit_and_team_parameters_are_supported(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
        ]);

        $reply = app(LineScheduleBot::class)->respond('!val 明天 tier=all limit=1 team="Team Alpha"');

        $this->assertStringContainsString('VALORANT｜08/12｜全部 Tier', $reply);
        $this->assertStringContainsString("Team Alpha\nvs\nTeam Beta", $reply);
        $this->assertStringNotContainsString('Previous Day Match', $reply);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/valorant/matches/current?date=2026-08-12', $reply);
        $this->assertStringNotContainsString('/lol/matches/alpha-vs-beta', $reply);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/valorant/matches/current')
            && $request['date'] === '2026-08-12'
            && ! isset($request['tiers']));
    }

    public function test_default_limit_keeps_more_than_ten_valorant_matches(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3HtmlWithManyMatches(11), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!val 明天');

        $this->assertNotNull($reply);
        $this->assertCount(11, $reply->imageData['matches']);
        $this->assertSame('台灣時間｜11 場賽程', $reply->imageData['subtitle']);
        $this->assertStringContainsString('第 11 場｜19:00｜BO3', $reply->text);
        $this->assertStringNotContainsString('另有 1 場', $reply->text);
    }

    public function test_tbd_match_missing_from_structured_data_is_included_from_the_visible_table(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3HtmlWithTbdMatch(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!val 明天');

        $this->assertNotNull($reply);
        $this->assertStringContainsString("TBD\nvs\nJD Gaming", $reply->text);
        $this->assertStringContainsString('第 2 場｜18:00｜BO3', $reply->text);
        $this->assertSame('台灣時間｜2 場賽程', $reply->imageData['subtitle']);
        $this->assertSame('TBD', $reply->imageData['matches'][1]['team1']);
        $this->assertSame('JD Gaming', $reply->imageData['matches'][1]['team2']);
        $this->assertSame('VCT 2026: China Stage 2', $reply->imageData['matches'][1]['tournament']);
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
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/api/v1/matches/'));
    }

    public function test_bo3_moneyline_is_used_when_selected_bookmakers_have_no_odds(): void
    {
        config([
            'services.odds.api_key' => 'odds-test-key',
            'services.odds.bookmakers' => null,
        ]);

        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3HtmlWithTbdMatch(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.odds-api.io/v3/events*' => Http::response([[
                'id' => 6539993323,
                'home' => 'All Gamers',
                'away' => 'TEC Esports',
                'date' => '2026-08-12T08:00:00Z',
                'status' => 'pending',
            ]], 200),
            'https://api.odds-api.io/v3/bookmakers/selected*' => Http::response([
                'bookmakers' => ['Stake', 'Bet365'],
            ], 200),
            'https://api.odds-api.io/v3/odds/multi*' => Http::response([], 200),
            'https://bo3.gg/api/v1/matches/all-gamers-vs-tec-esports-12-08-2026' => Http::response([
                'team1' => ['name' => 'All Gamers'],
                'team2' => ['name' => 'Titan Esports Club'],
                'bet_updates' => [
                    'team_1' => [
                        'name' => 'All Gamers',
                        'coeff' => 1.588,
                        'active' => true,
                    ],
                    'team_2' => [
                        'name' => 'Titan Esports Club',
                        'coeff' => 2.302,
                        'active' => true,
                    ],
                    'bet_provider_id' => 39,
                ],
            ], 200),
            'https://bo3.gg/api/v1/bet_providers' => Http::response([
                'results' => [[
                    'id' => 39,
                    'name' => '1xbit',
                ]],
            ], 200),
        ]);

        $reply = app(LineScheduleBot::class)->respond('!val 明天 team="All Gamers"');

        $this->assertStringContainsString('All Gamers　1.59（1xbit）', $reply);
        $this->assertStringContainsString('Titan Esports Club　2.30（1xbit）', $reply);
        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://bo3.gg/api/v1/matches/all-gamers-vs-tec-esports-12-08-2026');
    }

    public function test_schedule_command_queries_all_three_games_by_default(): void
    {
        $this->fakeScheduleImage();

        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-token',
                'message' => [
                    'id' => '123',
                    'type' => 'text',
                    'text' => '!賽程 明天',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->callWebhook($body, $this->signature($body));

        $response->assertOk()->assertExactJson([]);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/lol/matches/current')
            && $request['date'] === '2026-08-12'
            && $request['tiers'] === 's');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/valorant/matches/current')
            && $request['date'] === '2026-08-12'
            && $request['tiers'] === 's');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/matches/current')
            && $request['date'] === '2026-08-12'
            && $request['tiers'] === 's');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/reply') {
                return false;
            }

            $messages = $request['messages'];

            return $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['replyToken'] === 'reply-token'
                && count($messages) === 1
                && $messages[0]['type'] === 'image';
        });
    }

    public function test_schedule_command_supports_game_parameter_filter(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!賽程 08/12 game=lol/cs');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('綜合賽程（LoL/CS2）｜08/12｜S Tier', $reply->text);
        $this->assertSame('綜合賽程｜08/12｜S Tier', $reply->imageData['title']);
        $this->assertSame('all', $reply->imageData['game']);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/lol/matches/current'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/matches/current'));
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://bo3.gg/valorant/matches/current'));
    }

    public function test_match_alias_is_equivalent_to_schedule_command(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
            'https://api.line.me/*' => Http::response(['sentMessages' => []], 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!match 今天');

        $this->assertNotNull($reply);
        $this->assertStringContainsString('綜合賽程（LoL/VALORANT/CS2）｜08/11｜S Tier', $reply->text);
        $this->assertSame('綜合賽程｜08/11｜S Tier', $reply->imageData['title']);
    }

    public function test_bare_schedule_commands_default_to_today(): void
    {
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://bo3.gg/matches/current*' => Http::response($this->bo3Html(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response(['results' => []], 200),
        ]);

        foreach (['!match', '!lol', '!val', '!cs'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);

            $this->assertNotNull($reply, "{$command} should be recognized.");
            $this->assertStringContainsString('｜08/11｜S Tier', $reply->text);
        }
    }

    public function test_today_schedule_keeps_live_matches_and_excludes_finished_matches(): void
    {
        Http::fake([
            'https://bo3.gg/matches/current*' => Http::response($this->todayHtmlWithStartedAndUpcomingMatches(), 200),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!cs 08/11');

        $this->assertNotNull($reply);
        $this->assertStringNotContainsString('Past Team', $reply->text);
        $this->assertStringContainsString('Current Team', $reply->text);
        $this->assertStringContainsString('【滾球】', $reply->text);
        $this->assertStringContainsString('目前比分｜1：0（當局 6：14）', $reply->text);
        $this->assertStringContainsString('Future Team', $reply->text);
        $this->assertCount(2, $reply->imageData['matches']);
        $this->assertSame('09:00', $reply->imageData['matches'][0]['start_time']);
        $this->assertTrue($reply->imageData['matches'][0]['is_live']);
        $this->assertSame('1：0', $reply->imageData['matches'][0]['series_score']);
        $this->assertSame('6：14', $reply->imageData['matches'][0]['score']);
        $this->assertSame('10:00', $reply->imageData['matches'][1]['start_time']);
        $this->assertFalse($reply->imageData['matches'][1]['is_live']);
        $this->assertNull($reply->imageData['matches'][1]['series_score']);
        $this->assertNull($reply->imageData['matches'][1]['score']);
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

    private function todayHtmlWithStartedAndUpcomingMatches(): string
    {
        $events = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => 'Past Team vs Old Team',
                'url' => 'https://bo3.gg/matches/past-team-vs-old-team',
                'startDate' => '2026-08-11T00:00:00.000+00:00',
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => 'Current Team vs Live Team',
                'url' => 'https://bo3.gg/matches/current-team-vs-live-team',
                'startDate' => '2026-08-11T01:00:00.000+00:00',
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => 'Future Team vs Next Team',
                'url' => 'https://bo3.gg/matches/future-team-vs-next-team',
                'startDate' => '2026-08-11T02:00:00.000+00:00',
            ],
        ];

        return '<html><div class="table-row table-row--current">'
            .'<a href="/matches/current-team-vs-live-team">'
            .'<div class="team-name">Current Team</div>'
            .'<div class="c-match-series-score">1 - 0</div>'
            .'<div class="c-match-score">6 - 14</div>'
            .'<div class="team-name">Live Team</div>'
            .'<span class="bo-type">Bo3</span>'
            .'</a><p class="tournament-name">Live Test League</p></div>'
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }

    private function bo3HtmlWithTbdMatch(): string
    {
        $events = [[
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            'name' => 'All Gamers vs Titan Esports Club',
            'url' => 'https://bo3.gg/valorant/matches/all-gamers-vs-tec-esports-12-08-2026',
            'startDate' => '2026-08-12T08:00:00.000+00:00',
        ]];
        $knownRow = '<div class="table-row table-row--upcoming">'
            .'<a href="/valorant/matches/all-gamers-vs-tec-esports-12-08-2026" class="c-global-match-link table-cell">'
            .'<span class="time">08:00</span>'
            .'<div class="team-name">All Gamers</div>'
            .'<span class="bo-type">Bo3</span>'
            .'<div class="team-name">Titan Esports Club</div>'
            .'</a>'
            .'<div class="table-cell tournament">'
            .'<p class="tournament-name">VCT 2026: China Stage 2</p>'
            .'</div>'
            .'</div>';
        $tbdRow = '<div class="table-row table-row--upcoming">'
            .'<button class="c-global-match-link c-global-match-link--disabled table-cell">'
            .'<span class="time">10:00</span>'
            .'<div class="team-name">TBD</div>'
            .'<span class="bo-type">Bo3</span>'
            .'<div class="team-name">JD Gaming</div>'
            .'</button>'
            .'<div class="table-cell tournament">'
            .'<p class="tournament-name">VCT 2026: China Stage 2</p>'
            .'</div>'
            .'</div>';

        return '<html>'.$knownRow.$tbdRow
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }

    private function bo3HtmlWithManyMatches(int $count): string
    {
        $events = [];
        $rows = [];

        foreach (range(1, $count) as $number) {
            $slug = "team-{$number}-alpha-vs-team-{$number}-beta-12-08-2026";
            $hour = $number;
            $events[] = [
                '@context' => 'https://schema.org',
                '@type' => 'SportsEvent',
                'name' => "Team {$number} Alpha vs Team {$number} Beta",
                'url' => "https://bo3.gg/valorant/matches/{$slug}",
                'startDate' => sprintf('2026-08-12T%02d:00:00.000+00:00', $hour),
            ];
            $rows[] = '<div class="table-row table-row--upcoming">'
                ."<a href=\"/valorant/matches/{$slug}\">"
                .sprintf('<span class="time">%02d:00</span>', $hour)
                ."<div class=\"team-name\">Team {$number} Alpha</div>"
                .'<span class="bo-type">Bo3</span>'
                ."<div class=\"team-name\">Team {$number} Beta</div></a>"
                .'<p class="tournament-name">VCT 2026: Test Stage</p></div>';
        }

        return '<html>'.implode('', $rows)
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }
}
