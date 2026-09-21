<?php

namespace Tests\Feature;

use App\Services\Bo3ScheduleService;
use App\Services\LineScheduleBot;
use App\Services\MlbScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MlbScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bo3.timezone' => 'Asia/Taipei',
            'services.odds.api_key' => null,
            'mlb.api_url' => 'https://statsapi.mlb.com/api/v1',
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_teams_taiwan_date_boundaries_and_doubleheaders(): void
    {
        $this->assertSame([119, 158], config('mlb.team_ids'));
        $games = [
            $this->game(1, '2026-09-20T15:59:59Z', 119, 147),
            $this->game(2, '2026-09-20T16:00:00Z', 119, 147),
            $this->game(3, '2026-09-21T07:00:00Z', 119, 158),
            $this->game(3, '2026-09-21T07:00:00Z', 119, 158),
            $this->game(4, '2026-09-21T12:00:00Z', 119, 158),
            $this->game(5, '2026-09-21T15:59:59Z', 158, 147),
            $this->game(6, '2026-09-21T16:00:00Z', 119, 147),
            $this->game(7, '2026-09-21T07:00:00Z', 147, 121),
        ];
        Http::fake(['https://statsapi.mlb.com/*' => Http::response($this->payload($games))]);
        $day = CarbonImmutable::parse('2026-09-21', 'Asia/Taipei');
        $matches = app(MlbScheduleService::class)->forRange($day, $day);
        $this->assertSame([2, 3, 4, 5], array_column($matches, 'game_pk'));
        $this->assertSame('00:00', $matches[0]['start_at']->format('H:i'));
        $this->assertSame('洛杉磯道奇', $matches[0]['team1']);
        Http::assertSent(fn (Request $r): bool => $r['teamId'] === '119,158' && $r['sportId'] === 1
            && $r['startDate'] === '2026-09-20' && $r['endDate'] === '2026-09-22');
        Http::assertSentCount(1);
    }

    public function test_configuration_can_select_other_teams_or_disable_mlb(): void
    {
        config(['mlb.team_ids' => [147]]);
        Http::fake(['https://statsapi.mlb.com/*' => Http::response($this->payload([
            $this->game(1, '2026-09-21T08:00:00Z', 147, 121),
            $this->game(2, '2026-09-21T08:00:00Z', 119, 158),
        ]))]);
        $day = CarbonImmutable::parse('2026-09-21', 'Asia/Taipei');
        $service = app(MlbScheduleService::class);
        $this->assertSame([1], array_column($service->forRange($day, $day), 'game_pk'));
        Http::assertSent(fn (Request $r): bool => $r['teamId'] === '147');
        config(['mlb.team_ids' => []]);
        $this->assertSame([], $service->forRange($day, $day));
        Http::assertSentCount(1);
    }

    public function test_recent_form_batches_both_teams_deduplicates_and_refreshes_without_cache(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        $games = $this->history();
        $games[] = $games[0];
        $games[] = $this->game(99, '2026-09-22T01:00:00Z', 119, 158, 'Final', 0, 1);
        $games[] = $this->game(98, '2026-09-21T01:00:00Z', 119, 158, 'Live', 0, 1);
        Http::fake(['https://statsapi.mlb.com/*' => Http::response($this->payload($games))]);
        $service = app(MlbScheduleService::class);
        $match = ['game' => 'mlb', 'team1_id' => 119, 'team2_id' => 158, 'start_at' => CarbonImmutable::parse('2026-09-22T01:00:00Z')];
        $matches = $service->enrichRecentForm([$match, $match]);
        $this->assertSame(['W', 'L', 'W', 'W', 'L'], $matches[0]['recent_form']['team1']['results']);
        $this->assertSame(['L', 'W', 'L', 'L', 'W'], $matches[0]['recent_form']['team2']['results']);
        $this->assertSame($matches[0]['recent_form'], $matches[1]['recent_form']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['teamId'] === '119,158');
        $service->enrichRecentForm([$match]);
        Http::assertSentCount(2);
    }

    public function test_history_window_extends_only_when_needed_and_excludes_later_games(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => fn (Request $r) => Http::response($this->payload(
            $r['startDate'] === '2026-08-20'
                ? [$this->game(1, '2026-09-19T01:00:00Z', 119, 158, 'Final', 10, 0)]
                : [$this->game(2, '2026-08-01T01:00:00Z', 119, 158, 'Final', 0, 1)],
        ))]);
        $match = ['game' => 'mlb', 'team1_id' => 119, 'team2_id' => 158, 'start_at' => CarbonImmutable::parse('2026-09-19T01:00:00Z')];
        $result = app(MlbScheduleService::class)->enrichRecentForm([$match])[0];
        $this->assertSame(['L'], $result['recent_form']['team1']['results']);
        $this->assertSame(1, $result['recent_form']['team1']['sample_size']);
        Http::assertSentCount(2);
    }

    public function test_mlb_command_and_match_game_filter_share_dates_limits_and_recent_form(): void
    {
        $this->fakeCommandSchedule();
        foreach (['!mlb 明天', '!match 明天 game=mlb', '!mlb 0922~0923 limit=1 team=道奇'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertNotNull($reply);
            $this->assertTrue($reply->prefersImage());
            $this->assertStringNotContainsString('Tier', $reply->imageData['title']);
            $this->assertSame('MLB', substr($reply->imageData['title'], 0, 3));
            $this->assertCount(1, $reply->imageData['matches']);
            $this->assertSame('洛杉磯道奇', $reply->imageData['matches'][0]['team1']);
            $this->assertSame(['W', 'L', 'W', 'W', 'L'], $reply->imageData['matches'][0]['recent_form']['team1']['results']);
            $this->assertStringContainsString('近 5 場（新→舊）', $reply->text);
            $this->assertStringContainsString('勝 敗 勝 勝 敗', $reply->text);
        }
        Http::assertSentCount(6); // One schedule and one history call per command.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'bo3.gg'));
    }

    public function test_today_keeps_live_delayed_and_upcoming_but_omits_completed_and_cancelled(): void
    {
        $games = [
            $this->game(1, '2026-09-21T01:00:00Z', 119, 158, 'Final'),
            $this->game(2, '2026-09-21T02:00:00Z', 119, 158, 'Live', 3, 2),
            $this->game(3, '2026-09-21T02:30:00Z', 119, 158),
            $this->game(4, '2026-09-21T08:00:00Z', 119, 158),
            $this->game(5, '2026-09-21T09:00:00Z', 119, 158),
        ];
        $games[2]['status']['detailedState'] = 'Delayed Start';
        $games[4]['status']['detailedState'] = 'Postponed';
        Http::fake(['https://statsapi.mlb.com/*' => fn (Request $r) => Http::response($this->payload(
            $r['startDate'] === '2026-09-20' ? $games : $this->history(),
        ))]);
        $reply = app(LineScheduleBot::class)->reply('!mlb');
        $this->assertCount(3, $reply->imageData['matches']);
        $this->assertSame(['10:00', '10:30', '16:00'], array_column($reply->imageData['matches'], 'start_time'));
        $this->assertStringContainsString('目前比分｜3：2', $reply->text);
        $this->assertStringContainsString('延遲', $reply->text);
    }

    public function test_team_filter_and_limit_are_applied_before_fetching_visible_opponents_history(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => function (Request $r) {
            if ($r['startDate'] === '2026-09-21') {
                return Http::response($this->payload([
                    $this->game(50, '2026-09-22T01:00:00Z', 119, 147),
                    $this->game(51, '2026-09-22T02:00:00Z', 158, 121),
                    $this->game(52, '2026-09-22T03:00:00Z', 119, 110),
                ]));
            }
            $history = $this->history();
            foreach ($history as &$game) {
                foreach (['away', 'home'] as $side) {
                    if ($game['teams'][$side]['team']['id'] === 158) {
                        $game['teams'][$side]['team']['id'] = 147;
                    }
                }
            }

            return Http::response($this->payload($history));
        }]);
        $reply = app(LineScheduleBot::class)->reply('!mlb 明天 team=道奇 limit=1');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame('紐約洋基', $reply->imageData['matches'][0]['team2']);
        $this->assertSame(5, $reply->imageData['matches'][0]['recent_form']['team2']['sample_size']);
        Http::assertSent(fn (Request $r): bool => $r['startDate'] !== '2026-09-21' && $r['teamId'] === '119,147');
        Http::assertSentCount(2);
    }

    public function test_no_game_has_a_clear_reply_without_requesting_history(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => Http::response(['dates' => []])]);
        $reply = app(LineScheduleBot::class)->reply('!mlb 明天');
        $this->assertStringContainsString('MLB 09/22 查無賽程', $reply->text);
        $this->assertNull($reply->imageData);
        Http::assertSentCount(1);
    }

    public function test_unknown_start_time_is_labeled_and_upcoming_scores_are_not_shown(): void
    {
        $game = $this->game(50, '2026-09-22T01:00:00Z', 119, 158);
        $game['status']['startTimeTBD'] = true;
        Http::fake(['https://statsapi.mlb.com/*' => fn (Request $r) => Http::response($this->payload(
            $r['startDate'] === '2026-09-21' ? [$game] : $this->history(),
        ))]);
        $reply = app(LineScheduleBot::class)->reply('!mlb 明天');
        $this->assertSame('時間待定', $reply->imageData['matches'][0]['start_time']);
        $this->assertNull($reply->imageData['matches'][0]['score']);
        $this->assertStringContainsString('時間待定', $reply->text);
    }

    public function test_match_includes_mlb_and_esports_and_sorts_them_together(): void
    {
        $this->fakeCommandSchedule();
        $esport = [
            'game' => 'cs', 'name' => 'Alpha vs Beta', 'team1' => 'Alpha', 'team2' => 'Beta', 'format' => 'BO3', 'tournament' => 'Test Cup',
            'start_at' => CarbonImmutable::parse('2026-09-22T00:00:00Z')->setTimezone('Asia/Taipei'), 'url' => 'https://bo3.gg/matches/test',
        ];
        $this->mock(Bo3ScheduleService::class, function ($mock) use ($esport): void {
            $mock->shouldReceive('forRange')->once()->with(['lol', 'valorant', 'cs'], \Mockery::any(), \Mockery::any(), ['s'])->andReturn([$esport]);
            $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->once()->andReturnUsing(fn (array $matches): array => $matches);
        });
        Http::fake(['https://api.bo3.gg/api/v1/matches/test' => Http::response([])]);
        $reply = app(LineScheduleBot::class)->reply('!match 明天');
        $this->assertSame(['cs', 'mlb'], array_column($reply->imageData['matches'], 'game'));
        $this->assertStringContainsString('LoL/VALORANT/CS2/MLB', $reply->text);
        $this->assertStringContainsString('MLB 賽程｜https://www.mlb.com/schedule/', $reply->text);
    }

    public function test_mlb_failure_is_clear_and_does_not_hide_other_sports(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => Http::response([], 503)]);
        $this->assertSame('目前無法取得 MLB 賽程，請稍後再試。', app(LineScheduleBot::class)->reply('!mlb 明天')->text);
        $esport = [
            'game' => 'cs', 'name' => 'Alpha vs Beta', 'team1' => 'Alpha', 'team2' => 'Beta', 'format' => 'BO3', 'tournament' => 'Test Cup',
            'start_at' => CarbonImmutable::parse('2026-09-22T00:00:00Z'), 'url' => 'https://bo3.gg/matches/test',
        ];
        $this->mock(Bo3ScheduleService::class, function ($mock) use ($esport): void {
            $mock->shouldReceive('forRange')->once()->andReturn([$esport]);
            $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->once()->andReturnUsing(fn (array $matches): array => $matches);
        });
        Http::fake(['https://api.bo3.gg/api/v1/matches/test' => Http::response([])]);
        $reply = app(LineScheduleBot::class)->reply('!match 明天');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertStringContainsString('MLB 暫時無法取得', $reply->imageData['subtitle']);
    }

    public function test_unavailable_history_keeps_the_schedule_and_range_validation_still_applies(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => fn (Request $r) => $r['startDate'] === '2026-09-21'
            ? Http::response($this->payload([$this->game(50, '2026-09-22T01:00:00Z', 119, 158)]))
            : Http::response([], 503)]);
        $reply = app(LineScheduleBot::class)->reply('!mlb 明天');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertNull($reply->imageData['matches'][0]['recent_form']['team1']);
        $this->assertStringContainsString('暫無資料', $reply->text);
        $this->assertStringContainsString('最多支援 7 天', app(LineScheduleBot::class)->reply('!mlb 0922~0930')->text);
        Http::assertSentCount(2);
    }

    private function fakeCommandSchedule(): void
    {
        Http::fake(['https://statsapi.mlb.com/*' => fn (Request $r) => Http::response($this->payload(
            $r['startDate'] === '2026-09-21'
                ? [$this->game(50, '2026-09-22T01:00:00Z', 119, 158)] : $this->history(),
        ))]);
    }

    private function history(): array
    {
        return array_map(fn (int $i): array => $this->game(
            10 + $i, '2026-09-'.(20 - $i).'T10:00:00Z', $i === 1 ? 158 : 119, $i === 1 ? 119 : 158,
            'Final', $i === 4 ? 0 : 4, $i === 4 ? 4 : 0,
        ), range(0, 4));
    }

    private function game(int $id, string $date, int $away, int $home, string $state = 'Preview', int $awayScore = 0, int $homeScore = 0): array
    {
        return [
            'gamePk' => $id, 'gameDate' => $date,
            'status' => ['abstractGameState' => $state, 'detailedState' => $state === 'Preview' ? 'Scheduled' : ($state === 'Live' ? 'In Progress' : 'Final')],
            'teams' => [
                'away' => ['team' => ['id' => $away, 'name' => 'Away Team'], 'score' => $awayScore],
                'home' => ['team' => ['id' => $home, 'name' => 'Home Team'], 'score' => $homeScore],
            ],
        ];
    }

    private function payload(array $games): array
    {
        return ['dates' => [['games' => $games]]];
    }
}
