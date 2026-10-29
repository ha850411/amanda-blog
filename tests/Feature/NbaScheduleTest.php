<?php

namespace Tests\Feature;

use App\Services\Bo3ScheduleService;
use App\Services\LineScheduleBot;
use App\Services\NbaScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NbaScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bo3.timezone' => 'Asia/Taipei',
            'services.odds.api_key' => null,
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.odds.bookmakers' => 'Stake,Bet365',
            'services.odds.bookmaker_priority' => 'Stake,Bet365',
            'nba.api_url' => 'https://site.api.espn.com/apis/site/v2/sports/basketball/nba',
            'mlb.team_ids' => [],
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_teams_taiwan_boundaries_and_duplicate_games(): void
    {
        $this->assertSame([20, 11, 18, 14, 25, 24, 16, 9], config('nba.team_ids'));
        $games = [
            $this->game(1, '2026-11-14T15:59:59Z', 20, 13),
            $this->game(2, '2026-11-14T16:00:00Z', 13, 20),
            $this->game(3, '2026-11-15T02:00:00Z', 9, 11),
            $this->game(3, '2026-11-15T02:00:00Z', 9, 11),
            $this->game(4, '2026-11-15T15:59:59Z', 24, 13),
            $this->game(5, '2026-11-15T16:00:00Z', 18, 14),
            $this->game(6, '2026-11-15T02:00:00Z', 13, 2),
        ];
        Http::fake(['*/scoreboard*' => Http::response(['events' => $games])]);
        $day = CarbonImmutable::parse('2026-11-15', 'Asia/Taipei');
        $matches = app(NbaScheduleService::class)->forRange($day, $day);
        $this->assertSame(['2', '3', '4'], array_column($matches, 'event_id'));
        $this->assertSame('00:00', $matches[0]['start_at']->format('H:i'));
        $this->assertSame('洛杉磯湖人', $matches[0]['team1']);
        $this->assertSame('費城76人', $matches[0]['team2']);
        $this->assertSame(['20261114', '20261115'], Http::recorded()->map(fn (array $pair): string => $pair[0]['dates'])->all());
        Http::assertSentCount(2);
    }

    public function test_teams_are_configurable_and_an_empty_list_skips_http(): void
    {
        config(['nba.team_ids' => ['13', 13, 0, 'invalid']]);
        Http::fake(['*/scoreboard*' => Http::response(['events' => [
            $this->game(1, '2026-11-15T02:00:00Z', 13, 2),
            $this->game(2, '2026-11-15T02:00:00Z', 9, 11),
        ]])]);
        $day = CarbonImmutable::parse('2026-11-15', 'Asia/Taipei');
        $service = app(NbaScheduleService::class);
        $this->assertSame(['1'], array_column($service->forRange($day, $day), 'event_id'));
        config(['nba.team_ids' => []]);
        $this->assertSame([], $service->forRange($day, $day));
        Http::assertSentCount(2);
    }

    public function test_recent_form_uses_both_teams_deduplicates_and_does_not_cache(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        $games = $this->history();
        $games[] = $games[0];
        $games[] = $this->game(90, '2026-11-16T01:00:00Z', 9, 13, 'STATUS_FINAL', 99, 100);
        $games[] = $this->game(91, '2026-11-15T01:00:00Z', 9, 13, 'STATUS_IN_PROGRESS', 50, 60);
        Http::fake(['*/teams/*/schedule*' => fn (Request $r) => Http::response(['events' => $r['seasontype'] === 2 ? $games : []])]);
        $service = app(NbaScheduleService::class);
        $match = $this->match();
        $matches = $service->enrichRecentForm([$match, $match]);
        $this->assertSame(['W', 'L', 'W', 'W', 'L'], $matches[0]['recent_form']['team1']['results']);
        $this->assertSame(['L', 'W', 'L', 'L', 'W'], $matches[0]['recent_form']['team2']['results']);
        $this->assertSame([
            'date' => '2026/11/14', 'opponent' => '洛杉磯湖人', 'format' => '單場',
            'team_score' => 110, 'opponent_score' => 100, 'result' => 'W',
        ], $matches[0]['recent_form']['team1']['matches'][0]);
        $this->assertSame(100, $matches[0]['recent_form']['team1']['matches'][1]['team_score']);
        $this->assertSame(110, $matches[0]['recent_form']['team1']['matches'][1]['opponent_score']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => $request['seasontype'] === 1);
        $service->enrichRecentForm([$match]);
        Http::assertSentCount(8);
    }

    public function test_history_includes_preseason_and_playoffs_and_extends_a_short_sample(): void
    {
        $match = $this->match();
        $match['start_at'] = CarbonImmutable::parse('2026-10-05T01:00:00Z');
        Http::fake(['*/teams/*/schedule*' => function (Request $r) {
            $games = match ([$r['season'], $r['seasontype']]) {
                [2027, 1] => [
                    $this->game(1, '2026-10-04T01:00:00Z', 9, 13, 'STATUS_FINAL', 110, 100),
                    $this->game(2, '2026-10-05T01:00:00Z', 9, 13, 'STATUS_FINAL', 110, 100),
                ],
                [2026, 3] => [$this->game(3, '2026-05-01T01:00:00Z', 9, 13, 'STATUS_FINAL', 90, 100)],
                [2026, 2] => [
                    $this->game(4, '2026-04-01T01:00:00Z', 9, 13, 'STATUS_FINAL', 110, 100),
                    $this->game(5, '2025-08-01T01:00:00Z', 9, 13, 'STATUS_FINAL', 110, 100),
                ],
                default => [],
            };

            return Http::response(['events' => $games]);
        }]);
        $result = app(NbaScheduleService::class)->enrichRecentForm([$match]);
        $this->assertSame(['2026/10/04', '2026/05/01', '2026/04/01'], array_column($result[0]['recent_form']['team1']['matches'], 'date'));
        $this->assertSame(['W', 'L', 'W'], $result[0]['recent_form']['team1']['results']);
        Http::assertSentCount(12);
    }

    public function test_command_range_team_limit_quick_replies_and_text_mode(): void
    {
        $this->fakeSchedule([
            $this->game(1, '2026-11-16T01:00:00Z', 9, 13),
            $this->game(2, '2026-11-16T03:00:00Z', 20, 11),
            $this->game(3, '2026-11-17T02:00:00Z', 9, 13),
        ]);
        foreach (['!nba 明天', '!match 明天 game=nba', '!NBA 1116~1117 team=勇士 limit=1'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertNotNull($reply->imageData);
            $this->assertStringStartsWith('NBA｜11/16', $reply->imageData['title']);
            $this->assertStringNotContainsString('Tier', $reply->text);
            $this->assertSame('金州勇士', $reply->imageData['matches'][0]['team1']);
            $this->assertSame(5, $reply->imageData['matches'][0]['recent_form']['team1']['sample_size']);
            $this->assertStringContainsString('客隊 vs 主隊｜未開打', $reply->text);
            $this->assertSame('https://www.espn.com/nba/scoreboard/_/date/20261115', $reply->linkUrl);
            $quick = array_column($reply->quickReplies, 'text');
            $this->assertContains('!nba', $quick);
            $this->assertContains('!nba 明天', $quick);
            $this->assertNotContains('!nba tier=s,a', $quick);
        }
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame('11/16 09:00', $reply->imageData['matches'][0]['start_time']);
        $this->assertStringContainsString('另有 1 場', $reply->text);
        $text = app(LineScheduleBot::class)->reply('!nba 明天 team=Warriors text');
        $this->assertNull($text->imageData);
        $this->assertStringContainsString('金州勇士', $text->text);
        Http::assertNotSent(fn (Request $r): bool => ! str_starts_with($r->url(), config('nba.api_url')));
        $this->assertStringContainsString('最多支援 7 天', app(LineScheduleBot::class)->reply('!nba 1116~1124')->text);
    }

    public function test_today_keeps_live_and_delayed_games_and_hides_finished_or_cancelled_games(): void
    {
        $games = array_map(fn (string $state, int $id): array => $this->game($id, '2026-11-15T01:00:00Z', 9, 13, $state, 110, 100), [
            'STATUS_FINAL', 'STATUS_IN_PROGRESS', 'STATUS_DELAYED', 'STATUS_SCHEDULED', 'STATUS_POSTPONED', 'STATUS_CANCELED',
        ], range(1, 6));
        $games[3]['competitions'][0]['timeValid'] = false;
        $this->fakeSchedule($games);
        $reply = app(LineScheduleBot::class)->reply('!nba');
        $this->assertCount(3, $reply->imageData['matches']);
        $this->assertSame(['進行中', '延遲', '未開打'], array_column($reply->imageData['matches'], 'status_label'));
        $this->assertSame('110：100', $reply->imageData['matches'][0]['score']);
        $this->assertNull($reply->imageData['matches'][1]['score']);
        $this->assertSame('時間待定', $reply->imageData['matches'][2]['start_time']);
        $this->assertStringContainsString('目前比分｜110：100', $reply->text);
    }

    public function test_combined_mlb_nba_command_has_sports_links_and_no_esports_tier_or_requests(): void
    {
        $this->fakeSchedule([$this->game(1, '2026-11-16T01:00:00Z', 9, 13)]);
        config(['services.odds.api_key' => 'test-key']);
        Http::fake(['https://api.odds-api.io/v3/events*' => Http::response([])]);
        $this->mock(Bo3ScheduleService::class, function ($mock): void {
            $mock->shouldNotReceive('forRange');
            $mock->shouldNotReceive('enrichLiveDetailsAndMissingFormats');
        });
        $reply = app(LineScheduleBot::class)->reply('!match 明天 game=mlb/nba');
        $this->assertSame('綜合賽程｜11/16', $reply->imageData['title']);
        $this->assertStringContainsString('綜合賽程（MLB/NBA）', $reply->text);
        $this->assertStringContainsString('NBA 賽程｜https://www.espn.com/nba/scoreboard/_/date/20261115', $reply->text);
        $this->assertStringNotContainsString('Tier', $reply->text);
        $this->assertNotContains('!match tier=s,a', array_column($reply->quickReplies, 'text'));
        Http::assertSent(fn (Request $r): bool => ($r['sport'] ?? null) === 'basketball' && $r['league'] === 'usa-nba');
        Http::assertNotSent(fn (Request $r): bool => ($r['sport'] ?? null) === 'esports');
    }

    public function test_nba_stake_moneyline_is_included_in_image_and_text_after_team_and_limit_filters(): void
    {
        $this->fakeSchedule([
            $this->game(1, '2026-11-16T01:00:00Z', 9, 13),
            $this->game(2, '2026-11-16T02:00:00Z', 20, 11),
        ]);
        config(['services.odds.api_key' => 'test-key']);
        Http::fake([
            'https://api.odds-api.io/v3/events*' => Http::response([
                ['id' => 101, 'home' => 'Los Angeles Lakers', 'away' => 'Golden State Warriors', 'date' => '2026-11-16T01:00:00Z',
                    'sport' => ['slug' => 'basketball'], 'league' => ['slug' => 'usa-nba']],
                ['id' => 102, 'home' => 'Indiana Pacers', 'away' => 'Philadelphia 76ers', 'date' => '2026-11-16T02:00:00Z',
                    'sport' => ['slug' => 'basketball'], 'league' => ['slug' => 'usa-nba']],
            ]),
            'https://api.odds-api.io/v3/odds/multi*' => Http::response([[
                'id' => 101, 'bookmakers' => [
                    'Stake' => [['name' => 'ML', 'odds' => [['home' => '1.70', 'away' => '2.11']]]],
                    'Bet365' => [['name' => 'ML', 'odds' => [['home' => '1.80', 'away' => '2.20']]]],
                ],
            ]]),
        ]);
        foreach (['!nba 明天 team=勇士 limit=1', '!match 明天 game=mlb/nba team=勇士 limit=1'] as $command) {
            $reply = app(LineScheduleBot::class)->reply($command);
            $this->assertCount(1, $reply->imageData['matches']);
            $this->assertSame(['price' => 2.11, 'bookmaker' => 'Stake'], $reply->imageData['matches'][0]['odds']['team1']);
            $this->assertSame(['price' => 1.70, 'bookmaker' => 'Stake'], $reply->imageData['matches'][0]['odds']['team2']);
            $this->assertStringContainsString('金州勇士　2.11（Stake）', $reply->text);
            $this->assertStringContainsString('洛杉磯湖人　1.70（Stake）', $reply->text);
        }
        $text = app(LineScheduleBot::class)->reply('!nba 明天 team=勇士 text');
        $this->assertNull($text->imageData);
        $this->assertStringContainsString('金州勇士　2.11（Stake）', $text->text);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/odds/multi') && $r['eventIds'] !== '101');
    }

    public function test_nba_schedule_remains_available_when_the_odds_provider_fails(): void
    {
        $this->fakeSchedule([$this->game(1, '2026-11-16T01:00:00Z', 9, 13)]);
        config(['services.odds.api_key' => 'test-key']);
        Http::fake(['https://api.odds-api.io/v3/events*' => Http::response([], 503)]);
        $reply = app(LineScheduleBot::class)->reply('!nba 明天');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertNull($reply->imageData['matches'][0]['odds']);
        $this->assertStringContainsString('獨贏賠率｜暫無盤口', $reply->text);
    }

    public function test_historical_final_scores_are_shown_in_away_home_order(): void
    {
        $this->fakeSchedule([$this->game(1, '2026-11-14T01:00:00Z', 9, 13, 'STATUS_FINAL', 110, 100)]);
        $reply = app(LineScheduleBot::class)->reply('!nba 1114');
        $this->assertStringContainsString('終場比分｜110：100', $reply->text);
        $this->assertSame('已結束', $reply->imageData['matches'][0]['status_label']);
    }

    public function test_only_visible_teams_get_history_and_one_teams_failure_does_not_hide_the_schedule(): void
    {
        $this->fakeSchedule([
            $this->game(1, '2026-11-16T01:00:00Z', 9, 13),
            $this->game(2, '2026-11-16T02:00:00Z', 20, 11),
        ], failedTeam: 13);
        $reply = app(LineScheduleBot::class)->reply('!nba 明天 team=勇士 limit=1');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertSame(5, $reply->imageData['matches'][0]['recent_form']['team1']['sample_size']);
        $this->assertNull($reply->imageData['matches'][0]['recent_form']['team2']);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/teams/20/') || str_contains($r->url(), '/teams/11/'));
        Http::assertSentCount(6);
    }

    public function test_default_match_merges_nba_with_esports_without_treating_nba_as_an_esport(): void
    {
        $this->fakeSchedule([$this->game(1, '2026-11-16T01:00:00Z', 9, 13)]);
        $this->mockEsports();
        $reply = app(LineScheduleBot::class)->reply('!match 明天');
        $this->assertSame(['cs', 'nba'], array_column($reply->imageData['matches'], 'game'));
        $this->assertStringContainsString('LoL/VALORANT/CS2/MLB/NBA', $reply->text);
        $this->assertStringContainsString('NBA 賽程｜https://www.espn.com/nba/scoreboard/_/date/20261115', $reply->text);
        $this->assertContains('!nba', array_column($reply->quickReplies, 'text'));
    }

    public function test_malformed_history_keeps_the_schedule_without_claiming_no_past_games(): void
    {
        Http::fake([
            '*/scoreboard*' => Http::response(['events' => [$this->game(1, '2026-11-16T01:00:00Z', 9, 13)]]),
            '*/teams/*/schedule*' => Http::response(['events' => [['competitions' => [['competitors' => 'invalid']]]]]),
        ]);
        $reply = app(LineScheduleBot::class)->reply('!nba 明天');
        $this->assertCount(1, $reply->imageData['matches']);
        $this->assertNull($reply->imageData['matches'][0]['recent_form']['team1']);
        $this->assertNull($reply->imageData['matches'][0]['recent_form']['team2']);
        $this->assertStringContainsString('金州勇士｜暫無資料', $reply->text);
        $this->assertStringNotContainsString('無已完賽紀錄', $reply->text);
    }

    public function test_empty_schedule_is_distinct_from_failed_or_malformed_feeds(): void
    {
        Http::fake(['*/scoreboard*' => Http::sequence()
            ->push(['events' => []])->push(['events' => []])
            ->pushStatus(503)->pushStatus(503)
            ->push(['unexpected' => []])->push(['unexpected' => []])
            ->pushFailedConnection()->pushFailedConnection(),
        ]);
        $reply = app(LineScheduleBot::class)->reply('!nba 明天');
        $this->assertStringContainsString('NBA 11/16 查無賽程', $reply->text);
        $this->assertStringNotContainsString('暫時無法取得', $reply->text);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $reply = app(LineScheduleBot::class)->reply('!nba 明天');
            $this->assertStringContainsString('NBA 暫時無法取得', $reply->text);
            $this->assertStringNotContainsString('查無賽程', $reply->text);
        }
    }

    public function test_nba_failure_keeps_other_games_and_warns_about_incomplete_results(): void
    {
        Http::fake(['*/scoreboard*' => Http::response([], 503)]);
        $this->mockEsports();
        $reply = app(LineScheduleBot::class)->reply('!match 明天');
        $this->assertSame(['cs'], array_column($reply->imageData['matches'], 'game'));
        $this->assertStringContainsString('NBA 暫時無法取得', $reply->imageData['subtitle']);
    }

    private function mockEsports(): void
    {
        $this->mock(Bo3ScheduleService::class, function ($mock): void {
            $mock->shouldReceive('forRange')->once()->with(['lol', 'valorant', 'cs'], \Mockery::any(), \Mockery::any(), ['s'])->andReturn([
                'matches' => [[
                    'game' => 'cs', 'name' => 'Alpha vs Beta', 'team1' => 'Alpha', 'team2' => 'Beta',
                    'format' => 'BO3', 'tournament' => 'Test Cup', 'start_at' => CarbonImmutable::parse('2026-11-16T00:00:00Z'),
                    'url' => 'https://bo3.gg/matches/test',
                ]],
                'failed_requests' => [],
            ]);
            $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->once()->andReturnUsing(fn (array $matches): array => $matches);
        });
        Http::fake(['https://api.bo3.gg/api/v1/matches/test' => Http::response([])]);
    }

    private function fakeSchedule(array $games, ?int $failedTeam = null): void
    {
        Http::fake([
            '*/scoreboard*' => Http::response(['events' => $games]),
            '*/teams/*/schedule*' => fn (Request $r) => $failedTeam !== null && str_contains($r->url(), '/teams/'.$failedTeam.'/')
                ? Http::response([], 503)
                : Http::response(['events' => $r['seasontype'] === 2 ? $this->history() : []]),
        ]);
    }

    private function history(): array
    {
        return array_map(function (int $i): array {
            $game = $this->game(10 + $i, '2026-11-'.(14 - $i).'T01:00:00Z', $i === 1 ? 13 : 9, $i === 1 ? 9 : 13,
                'STATUS_FINAL', $i === 4 ? 100 : 110, $i === 4 ? 110 : 100);
            // Team schedule scores are objects; scoreboard scores are strings.
            foreach ($game['competitions'][0]['competitors'] as &$team) {
                $team['score'] = ['value' => (float) $team['score'], 'displayValue' => $team['score']];
            }
            $game['competitions'][0]['status'] = $game['status'];
            unset($game['status']);

            return $game;
        }, range(0, 4));
    }

    private function match(): array
    {
        return ['game' => 'nba', 'team1_id' => 9, 'team2_id' => 13, 'start_at' => CarbonImmutable::parse('2026-11-16T01:00:00Z')];
    }

    private function game(int $id, string $date, int $away, int $home, string $status = 'STATUS_SCHEDULED', int $awayScore = 0, int $homeScore = 0): array
    {
        $names = [9 => 'Golden State Warriors', 13 => 'Los Angeles Lakers', 20 => 'Philadelphia 76ers', 11 => 'Indiana Pacers'];

        return [
            'id' => (string) $id, 'date' => $date, 'name' => $away === 9 ? 'Golden State Warriors at Los Angeles Lakers' : 'Away at Home',
            'status' => ['type' => [
                'name' => $status, 'state' => $status === 'STATUS_FINAL' ? 'post' : ($status === 'STATUS_IN_PROGRESS' ? 'in' : 'pre'),
                'completed' => $status === 'STATUS_FINAL',
            ]],
            'competitions' => [[
                'timeValid' => true, 'venue' => ['fullName' => 'Test Arena'],
                'competitors' => [
                    ['homeAway' => 'home', 'team' => ['id' => (string) $home, 'displayName' => $names[$home] ?? 'Home Team'], 'score' => (string) $homeScore],
                    ['homeAway' => 'away', 'team' => ['id' => (string) $away, 'displayName' => $names[$away] ?? 'Away Team'], 'score' => (string) $awayScore],
                ],
            ]],
        ];
    }
}
