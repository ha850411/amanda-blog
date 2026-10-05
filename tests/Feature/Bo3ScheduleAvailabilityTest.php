<?php

namespace Tests\Feature;

use App\Services\Bo3ScheduleService;
use App\Services\LineScheduleBot;
use App\Services\MlbScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Bo3ScheduleAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bo3.base_url' => 'https://bo3.gg',
            'services.bo3.api_url' => 'https://api.bo3.gg/api/v1',
            'services.bo3.timezone' => 'Asia/Taipei',
            'services.odds.api_key' => null,
            'mlb.team_ids' => [],
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_missing_markup_with_a_confirmed_empty_api_result_is_an_empty_schedule(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::response('<html><title>Valorant Matches</title></html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => []]),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));

        $this->assertSame([], $matches);
        Http::assertSentCount(2);
    }

    #[DataProvider('htmlFailures')]
    public function test_api_schedule_survives_unavailable_html(string $failure): void
    {
        Http::fake([
            'https://bo3.gg/*' => match ($failure) {
                'connection' => Http::failedConnection(),
                'http' => Http::response('Unavailable', 503),
                'json' => Http::response('<script id="micro-markup">{invalid}</script>'),
                default => Http::response('<html><title>Matches</title></html>'),
            },
            'https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => [$this->apiMatch(2)]]),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));

        $this->assertCount(1, $matches);
        $this->assertSame('Team 2', $matches[0]['team1']);
        $this->assertSame('BO3', $matches[0]['format']);
        $this->assertSame('15:00', $matches[0]['start_at']->format('H:i'));
    }

    public static function htmlFailures(): array
    {
        return [
            'missing markup' => ['markup'],
            'invalid JSON-LD' => ['json'],
            'HTTP failure' => ['http'],
            'connection failure' => ['connection'],
        ];
    }

    public function test_unrecognized_html_and_invalid_api_payload_are_not_an_empty_schedule(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::response('<html>Unavailable</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response(['error' => 'Unavailable']),
        ]);

        $this->expectException(RuntimeException::class);

        app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));
    }

    public function test_match_tier_s_a_keeps_lol_and_cs_when_valorant_has_no_markup_or_matches(): void
    {
        $this->fakeMixedSchedule();

        $reply = app(LineScheduleBot::class)->reply('!match tier=s,a');

        $this->assertNotNull($reply->imageData);
        $this->assertSame(['lol', 'cs'], array_column($reply->imageData['matches'], 'game'));
        $this->assertStringContainsString('S/A Tier', $reply->imageData['title']);
        $this->assertStringNotContainsString('暫時無法取得', $reply->text);
        $this->assertStringNotContainsString('查無賽程', $reply->text);
        Http::assertSent(fn (Request $request): bool => ($request['filter']['matches.tier']['in'] ?? null) === 's,a');
    }

    public function test_one_game_failure_keeps_other_games_and_displays_a_warning(): void
    {
        $this->fakeMixedSchedule(valorantFailed: true);

        $reply = app(LineScheduleBot::class)->reply('!match tier=s,a');

        $this->assertNotNull($reply->imageData);
        $this->assertSame(['lol', 'cs'], array_column($reply->imageData['matches'], 'game'));
        $this->assertStringContainsString('VALORANT', $reply->imageData['subtitle']);
        $this->assertStringContainsString('暫時無法取得', $reply->imageData['subtitle']);
    }

    public function test_one_day_failure_keeps_other_days_in_the_range(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::response('<html>Matches</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => function (Request $request) {
                $start = $request['filter']['matches.start_date']['gt'] ?? null;
                if ($start === null) {
                    return Http::response(['results' => []]);
                }
                $date = CarbonImmutable::parse($start)->addSecond()->setTimezone('Asia/Taipei')->format('Y-m-d');

                return $date === '2026-10-07'
                    ? Http::response([], 503)
                    : Http::response(['results' => [$this->apiMatch(3, $date)]]);
            },
            'https://api.bo3.gg/api/v1/matches/*' => Http::response([]),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!lol 1006~1008 tier=s,a');

        $this->assertNotNull($reply->imageData);
        $this->assertSame(['10/06 15:00', '10/08 15:00'], array_column($reply->imageData['matches'], 'start_time'));
        $this->assertStringContainsString('10/07', $reply->imageData['subtitle']);
        $this->assertStringContainsString('暫時無法取得', $reply->text);
    }

    public function test_failed_esports_and_filtered_finished_mlb_do_not_claim_no_matches_exist(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::response([], 503),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response([], 503),
        ]);
        $this->mock(MlbScheduleService::class, function ($mock): void {
            $mock->shouldReceive('forRange')->once()->andReturn([[
                'game' => 'mlb', 'name' => 'Away vs Home', 'team1' => 'Away', 'team2' => 'Home',
                'format' => 'BO1', 'tournament' => 'MLB', 'is_live' => false,
                'is_finished' => true, 'is_cancelled' => false,
                'start_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Taipei'),
                'url' => 'https://www.mlb.com/gameday/123',
            ]]);
            $mock->shouldReceive('filteredUrl')->andReturn('https://www.mlb.com/schedule/2026-10-04');
        });

        $reply = app(LineScheduleBot::class)->reply('!match tier=s,a');

        $this->assertNull($reply->imageData);
        $this->assertStringContainsString('bo3.gg', $reply->text);
        $this->assertStringContainsString('暫時無法取得', $reply->text);
        $this->assertStringNotContainsString('查無賽程', $reply->text);
    }

    public function test_same_site_api_recovers_a_failed_primary_api_with_the_same_filters(): void
    {
        $primaryRequests = [];
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<html>Unavailable</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => function (Request $request) use (&$primaryRequests) {
                $primaryRequests[] = $request;

                return Http::failedConnection()($request);
            },
            'https://bo3.gg/api/v1/matches?*' => Http::response(['results' => [$this->apiMatch(2)]]),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'), ['s', 'a']);

        $this->assertSame(['Team 2'], array_column($matches, 'team1'));
        $fallback = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://bo3.gg/api/'))->first()[0];
        $this->assertCount(2, $primaryRequests);
        $this->assertSame($primaryRequests[0]->data(), $fallback->data());
        $this->assertSame('s,a', $fallback['filter']['matches.tier']['in']);
        Http::assertSentCount(2); // HTML and fallback; connection failures have no recorded response.
    }

    public function test_invalid_primary_api_payload_uses_the_same_site_api(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<html>Unavailable</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response('<html>Unavailable</html>'),
            'https://bo3.gg/api/v1/matches?*' => Http::response(['results' => [$this->apiMatch(2)]]),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));

        $this->assertSame(['Team 2'], array_column($matches, 'team1'));
        Http::assertSentCount(3);
    }

    #[DataProvider('missingOrInvalidMarkup')]
    public function test_visible_html_schedule_survives_missing_structured_data_and_failed_apis(string $markup): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<html>'
                .'<div class="table-row table-row--upcoming"><a href="/valorant/matches/team-2">'
                .'<div class="team-name">Team 2</div><div class="team-name">Opponent</div></a>'
                .'<div class="time">07:00</div><div class="bo-type">Bo3</div>'
                .'<div class="tournament-name">Test Cup</div></div>'.$markup.'</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response([], 503),
            'https://bo3.gg/api/v1/matches?*' => Http::response([], 503),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));

        $this->assertSame(['Team 2'], array_column($matches, 'team1'));
        $this->assertSame('15:00', $matches[0]['start_at']->format('H:i'));
        $this->assertSame('Test Cup', $matches[0]['tournament']);
    }

    public static function missingOrInvalidMarkup(): array
    {
        return ['missing' => [''], 'malformed' => ['<script id="micro-markup">invalid</script>']];
    }

    public function test_a_transient_api_failure_retries_once_without_calling_the_fallback(): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<html>Unavailable</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::sequence()->push([], 503)->push(['results' => [$this->apiMatch(2)]]),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei'));

        $this->assertSame(['Team 2'], array_column($matches, 'team1'));
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://bo3.gg/api/'));
    }

    #[DataProvider('permanentHttpErrors')]
    public function test_client_errors_are_not_retried_or_sent_to_the_alternate_api(int $status): void
    {
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<script id="micro-markup">[]</script>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response([], $status),
        ]);

        $this->assertSame([], app(Bo3ScheduleService::class)->forDate('valorant', CarbonImmutable::today('Asia/Taipei')));
        Http::assertSentCount(2);
    }

    public static function permanentHttpErrors(): array
    {
        return ['not found' => [404], 'forbidden' => [403], 'rate limited' => [429]];
    }

    public function test_an_api_already_using_the_site_origin_is_not_requested_again_as_fallback(): void
    {
        config(['services.bo3.api_url' => 'https://bo3.gg/api/v1']);
        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response('<html>Unavailable</html>'),
            'https://bo3.gg/api/v1/matches?*' => Http::response([], 503),
        ]);

        $result = app(Bo3ScheduleService::class)->forRange(['valorant'], CarbonImmutable::today('Asia/Taipei'), CarbonImmutable::today('Asia/Taipei'));

        $this->assertCount(1, $result['failed_requests']);
        Http::assertSentCount(3);
    }

    public function test_total_failure_includes_affected_dates_and_a_schedule_link_and_logs_source_diagnostics(): void
    {
        Log::spy();
        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response('<html>Unavailable</html>'),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response([], 503),
            'https://bo3.gg/api/v1/matches?*' => fn (Request $request) => \GuzzleHttp\Promise\Create::rejectionFor(
                new \GuzzleHttp\Exception\ConnectException('Connection timed out', $request->toPsrRequest(), null, ['errno' => 28]),
            ),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!lol 1006 tier=s,a');

        $this->assertNull($reply->imageData);
        $this->assertStringContainsString('LoL 10/06', $reply->text);
        $this->assertStringContainsString('暫時無法取得', $reply->text);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/lol/matches/current?tiers=s,a&date=2026-10-06', $reply->text);
        $this->assertStringNotContainsString('查無賽程', $reply->text);
        Log::shouldHaveReceived('warning')->with('bo3.gg daily schedule unavailable from all sources.', \Mockery::on(
            fn (array $context): bool => $context['game'] === 'lol'
                && $context['date'] === '2026-10-06'
                && $context['html']['status'] === 200
                && $context['html']['reason'] === 'invalid_data'
                && $context['api']['status'] === 503
                && $context['fallback_api']['reason'] === 'timeout'
                && $context['fallback_api']['curl_errno'] === 28,
        ))->once();
    }

    private function fakeMixedSchedule(bool $valorantFailed = false): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::response('<html><title>Matches</title></html>'),
            'https://api.bo3.gg/api/v1/matches?*' => function (Request $request) use ($valorantFailed) {
                $discipline = (int) ($request['filter']['matches.discipline_id']['eq'] ?? 0);
                if (! isset($request['filter']['matches.start_date']['gt'])) {
                    return Http::response(['results' => []]);
                }
                if ($discipline === 2) {
                    return $valorantFailed ? Http::response([], 503) : Http::response(['results' => []]);
                }

                return Http::response(['results' => [$this->apiMatch($discipline)]]);
            },
            'https://api.bo3.gg/api/v1/matches/*' => Http::response([]),
        ]);
    }

    private function apiMatch(int $discipline, string $date = '2026-10-05'): array
    {
        return [
            'slug' => "team-{$discipline}-vs-opponent-{$date}",
            'start_date' => "{$date}T07:00:00+00:00",
            'bo_type' => 3,
            'tier' => 's',
            'status' => 'upcoming',
            'team1' => ['name' => "Team {$discipline}"],
            'team2' => ['name' => 'Opponent'],
            'tournament' => ['name' => 'Test Cup'],
        ];
    }
}
