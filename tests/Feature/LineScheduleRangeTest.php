<?php

namespace Tests\Feature;

use App\Services\LineScheduleBot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
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

    public function test_failed_required_schedule_source_returns_the_existing_error(): void
    {
        Http::fake([
            'https://bo3.gg/*' => Http::failedConnection(),
            'https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => []]),
        ]);

        $reply = app(LineScheduleBot::class)->reply('!lol 0914');

        $this->assertSame('目前無法取得 bo3.gg 賽程，請稍後再試。', $reply->text);
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
