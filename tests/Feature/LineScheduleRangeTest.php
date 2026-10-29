<?php

namespace Tests\Feature;

use App\Services\LineScheduleBot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LineScheduleRangeTest extends TestCase
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
            'nba.team_ids' => [],
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_two_days_includes_today_and_tomorrow_in_taiwan_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27T16:30:00Z'));
        $this->fakeRange();

        $reply = app(LineScheduleBot::class)->reply('!match 2d');

        $this->assertNotNull($reply);
        $this->assertSame('綜合賽程｜10/28 ~ 10/29｜S Tier', $reply->imageData['title']);
        $this->assertCount(12, $reply->imageData['matches']);
        $this->assertSame(['10/28', '10/29'], array_values(array_unique(array_map(
            fn (array $match): string => substr($match['start_time'], 0, 5),
            $reply->imageData['matches'],
        ))));
        $this->assertEquals($reply, app(LineScheduleBot::class)->reply('!match 1028~1029'));
    }

    #[DataProvider('relativeDayCommands')]
    public function test_relative_days_support_aliases_options_and_calendar_boundaries(string $command, string $today, string $label): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($today, 'Asia/Taipei'));
        Http::fake(['*' => Http::response(['results' => []])]);

        $reply = app(LineScheduleBot::class)->reply($command);

        $this->assertNotNull($reply);
        $this->assertStringContainsString($label.' 查無賽程。', $reply->text);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches/'));
    }

    public static function relativeDayCommands(): array
    {
        return [
            'one day' => ['!match 1d', '2026-10-28', '10/28'],
            'seven days across months' => ['!lol 7d', '2026-10-28', '10/28 ~ 11/03'],
            'across years with options' => ['!match 2D game=cs tier=all limit=1 team=G2 text', '2026-12-31', '12/31 ~ 01/01'],
            'valorant' => ['!val 2d', '2026-10-28', '10/28 ~ 10/29'],
            'cs2' => ['!cs2 2d', '2026-10-28', '10/28 ~ 10/29'],
            'mlb' => ['!mlb 2d', '2026-10-28', '10/28 ~ 10/29'],
            'nba' => ['!nba 2d', '2026-10-28', '10/28 ~ 10/29'],
        ];
    }

    public function test_invalid_day_counts_return_guidance_without_fetching_schedules(): void
    {
        foreach (['0d', '8d', '999999999999999999999d'] as $days) {
            $reply = app(LineScheduleBot::class)->reply('!match '.$days);
            $this->assertSame('查詢天數請輸入 1～7 天，例如 !match 2d（包含今天）。', $reply->text);
        }
        Http::assertNothingSent();
    }

    public function test_today_only_fetches_missing_formats_for_visible_upcoming_matches(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 00:30:00', 'Asia/Taipei'));
        $this->fakeRange();

        $reply = app(LineScheduleBot::class)->reply('!match 2d limit=1');

        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame('lol-2026-10-28-0', $reply->imageData['matches'][0]['team1']);
        $this->assertSame('BO3', $reply->imageData['matches'][0]['format']);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches/')));
    }

    public function test_five_day_match_command_keeps_order_and_limit_and_only_enriches_visible_matches(): void
    {
        $this->fakeRange();

        $reply = app(LineScheduleBot::class)->reply('!match 0914~0918');

        $this->assertNotNull($reply);
        $this->assertSame('綜合賽程｜09/14 ~ 09/18｜S Tier', $reply->imageData['title']);
        $this->assertCount(19, $reply->imageData['matches']);
        $this->assertStringContainsString('另有 11 場', $reply->text);
        $expectedTeams = [];
        foreach (range(14, 18) as $day) {
            foreach (range(0, 1) as $slot) {
                foreach (['lol', 'valorant', 'cs'] as $game) {
                    $expectedTeams[] = "{$game}-2026-09-{$day}-{$slot}";
                }
            }
        }
        $this->assertSame(array_slice($expectedTeams, 0, 19), array_column($reply->imageData['matches'], 'team1'));
        $this->assertSame(['BO3'], array_values(array_unique(array_column($reply->imageData['matches'], 'format'))));
        $this->assertCount(15, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/matches/current?')));
        $this->assertCount(15, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches?')));

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches/')
            && ! in_array(basename($request->url()), array_slice($expectedTeams, 0, 19), true));
    }

    public function test_range_applies_team_and_limit_before_fetching_missing_formats(): void
    {
        $this->fakeRange();

        $reply = app(LineScheduleBot::class)->reply('!match 0914~0918 team=cs-2026-09-18 limit=1 tier=all');

        $this->assertNotNull($reply);
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame('cs-2026-09-18-0', $reply->imageData['matches'][0]['team1']);
        $this->assertSame('09/18 08:00', $reply->imageData['matches'][0]['start_time']);
        $this->assertSame('BO3', $reply->imageData['matches'][0]['format']);
        $this->assertStringContainsString('另有 1 場', $reply->text);

        Http::assertNotSent(fn (Request $request): bool => isset($request['tiers'])
            || isset($request['filter']['matches.tier']));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches/')
            && basename($request->url()) !== 'cs-2026-09-18-0');
    }

    public function test_all_schedule_sources_failing_returns_an_error_with_the_requested_schedule_link(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::failedConnection(),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response([], 503),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!lol 0914');

        $this->assertStringContainsString('LoL 09/14', $reply->text);
        $this->assertStringContainsString('暫時無法取得', $reply->text);
        $this->assertStringContainsString('bo3.gg（LoL 09/14）', $reply->text);
        $this->assertStringContainsString('完整賽程｜https://bo3.gg/lol/matches/current?tiers=s&date=2026-09-14', $reply->text);
        $this->assertStringNotContainsString('查無賽程', $reply->text);
        $this->assertNull($reply->imageData);
    }

    public function test_range_including_today_does_not_fetch_details_for_hidden_future_matches(): void
    {
        $this->fakeRange();
        $reply = app(LineScheduleBot::class)->reply('!match 0913~0918 limit=1');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame('lol-2026-09-14-0', $reply->imageData['matches'][0]['team1']);
        $this->assertSame('BO3', $reply->imageData['matches'][0]['format']);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches/')
            && ! str_contains($request->url(), '2026-09-13-')
            && basename($request->url()) !== 'lol-2026-09-14-0');
    }

    private function fakeRange(): void
    {
        Http::fake([
            'https://bo3.gg/*' => function (Request $request) {
                $game = str_contains($request->url(), '/lol/') ? 'lol'
                    : (str_contains($request->url(), '/valorant/') ? 'valorant' : 'cs');
                $date = $request['date'];
                $prefix = $game === 'cs' ? '' : '/'.$game;
                $events = [];

                foreach (range(0, 1) as $slot) {
                    $team = "{$game}-{$date}-{$slot}";
                    $events[] = [
                        '@type' => 'SportsEvent',
                        'name' => $team.' vs Opponent',
                        'url' => "https://bo3.gg{$prefix}/matches/{$team}",
                        'startDate' => "{$date}T0{$slot}:00:00+00:00",
                    ];
                }

                return Http::response('<html><script id="micro-markup">'
                    .json_encode($events, JSON_THROW_ON_ERROR).'</script></html>');
            },
            'https://api.bo3.gg/api/v1/matches/*' => Http::response(['bo_type' => 3]),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => []]),
        ]);
    }
}
