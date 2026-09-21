<?php

namespace Tests\Feature;

use App\Services\Bo3HeadToHeadService;
use App\Services\Bo3OddsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bo3RecentFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bo3.api_url' => 'https://api.bo3.gg/api/v1', 'services.bo3.timezone' => 'Asia/Taipei']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_all_esports_use_each_teams_own_results_including_reversed_sides_and_draws(): void
    {
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => [
            $this->row(1, 9, 2, 0, '2026-09-20T12:00:00Z'),
            $this->row(8, 1, 2, 0, '2026-09-19T12:00:00Z'),
            $this->row(1, 7, 1, 1, '2026-09-18T12:00:00Z'),
            $this->row(6, 1, 0, 2, '2026-09-17T12:00:00Z'),
            $this->row(1, 5, 0, 2, '2026-09-16T12:00:00Z'),
            $this->row(1, 4, 2, 0, '2026-09-15T12:00:00Z'),
            $this->row(2, 9, 0, 2, '2026-09-20T12:00:00Z'),
        ]])]);

        $matches = app(Bo3HeadToHeadService::class)->enrich(array_map(fn (string $game): array => $this->match($game), ['lol', 'valorant', 'cs']));
        foreach ($matches as $match) {
            $this->assertSame(['W', 'L', 'D', 'W', 'L'], $match['recent_form']['team1']['results']);
            $this->assertSame(5, $match['recent_form']['team1']['sample_size']);
            $this->assertSame(2, $match['recent_form']['team1']['wins']);
            $this->assertSame(2, $match['recent_form']['team1']['losses']);
            $this->assertSame(['L'], $match['recent_form']['team2']['results']);
            $this->assertSame([
                'date' => '2026/09/20', 'opponent' => 'Team 9', 'format' => 'BO3',
                'team_score' => 2, 'opponent_score' => 0, 'result' => 'W',
            ], $match['recent_form']['team1']['matches'][0]);
            $this->assertSame([
                'date' => '2026/09/19', 'opponent' => 'Team 8', 'format' => 'BO3',
                'team_score' => 0, 'opponent_score' => 2, 'result' => 'L',
            ], $match['recent_form']['team1']['matches'][1]);
            $this->assertCount(5, $match['recent_form']['team1']['matches']);
            $this->assertSame('D', $match['recent_form']['team1']['matches'][2]['result']);
            $this->assertNull($match['h2h']); // Each team's form is independent of H2H.
        }
        Http::assertSentCount(9);
        $this->assertCount(6, Http::recorded(fn (Request $request): bool => ($request['with'] ?? '') === 'teams'));
    }

    public function test_duplicate_teams_share_requests_but_later_invocations_fetch_fresh_data_without_cache(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => []])]);
        $service = app(Bo3HeadToHeadService::class);
        $matches = [$this->match('lol'), array_replace($this->match('lol'), ['team2_id' => 3])];
        $service->enrich($matches);
        Http::assertSentCount(5); // Two pairings + three distinct teams; no detail calls.
        $service->enrich($matches);
        Http::assertSentCount(10);
    }

    public function test_historical_form_excludes_the_current_match_future_and_unfinished_results(): void
    {
        $rows = [
            $this->row(1, 2, 2, 0, '2026-09-19T10:00:00Z'),
            $this->row(1, 9, 2, 0, '2026-09-20T10:00:00Z'),
            array_replace($this->row(1, 9, 2, 0, '2026-09-18T10:00:00Z'), ['status' => 'live']),
            array_replace($this->row(1, 9, 2, 0, '2026-09-18T11:00:00Z'), ['end_date' => '2026-09-20T11:00:00Z']),
            $this->row(9, 1, 2, 0, '2026-09-17T10:00:00Z'),
        ];
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => $rows])]);
        $match = array_replace($this->match('cs'), ['start_at' => CarbonImmutable::parse('2026-09-19T10:00:00Z')]);
        $result = app(Bo3HeadToHeadService::class)->enrich([$match])[0];
        $this->assertSame(['L'], $result['recent_form']['team1']['results']);
        Http::assertSent(fn (Request $request): bool => $request['filter']['matches.start_date']['lt'] === '2026-09-19T10:00:00+00:00');
    }

    public function test_one_failed_team_history_keeps_other_results_and_schedule(): void
    {
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => fn (Request $request) => $request['filter']['matches.team_ids']['contains'] === '1'
                ? Http::response([], 503)
                : Http::response(['results' => [$this->row(2, 9, 2, 0, '2026-09-20T10:00:00Z')]])]);
        $result = app(Bo3HeadToHeadService::class)->enrich([$this->match('valorant')])[0];
        $this->assertNull($result['recent_form']['team1']);
        $this->assertSame(['W'], $result['recent_form']['team2']['results']);
        $this->assertSame('Alpha', $result['team1']);
    }

    public function test_existing_full_details_are_reused_by_history_and_odds(): void
    {
        config(['services.odds.api_key' => 'test', 'services.bo3.base_url' => 'https://bo3.gg']);
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => []])]);
        $match = $this->match('lol');
        $match['bo3_detail'] = ['team1_id' => 1, 'team2_id' => 2, 'discipline_id' => 3, 'bet_updates' => null];
        $matches = app(Bo3HeadToHeadService::class)->enrich([$match]);
        app(Bo3OddsService::class)->enrichMissing($matches);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/matches/'));
    }

    public function test_failed_detail_enrichment_can_still_use_team_ids_from_the_schedule(): void
    {
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => [$this->row(1, 9, 2, 0, '2026-09-20T10:00:00Z')]])]);
        $match = array_replace($this->match('lol'), ['bo3_detail' => []]);
        $result = app(Bo3HeadToHeadService::class)->enrich([$match])[0];
        $this->assertSame(['W'], $result['recent_form']['team1']['results']);
        Http::assertSentCount(3);
    }

    public function test_detail_dates_use_taiwan_time_and_missing_names_are_explicit(): void
    {
        $row = $this->row(9, 1, 0, 2, '2026-09-20T18:00:00Z');
        unset($row['team1'], $row['bo_type']);
        Http::fake(['https://api.bo3.gg/api/v1/matches?*' => Http::response(['results' => [$row]])]);
        $result = app(Bo3HeadToHeadService::class)->enrich([$this->match('cs')])[0];
        $this->assertSame([
            'date' => '2026/09/21', 'opponent' => '對手不明', 'format' => '—',
            'team_score' => 2, 'opponent_score' => 0, 'result' => 'W',
        ], $result['recent_form']['team1']['matches'][0]);
    }

    private function match(string $game): array
    {
        return [
            'game' => $game, 'team1' => 'Alpha', 'team2' => 'Beta', 'team1_id' => 1, 'team2_id' => 2,
            'discipline_id' => ['cs' => 1, 'valorant' => 2, 'lol' => 3][$game],
            'start_at' => CarbonImmutable::parse('2026-09-22T10:00:00Z'),
            'url' => 'https://bo3.gg/matches/alpha-beta',
        ];
    }

    private function row(int $first, int $second, int $firstScore, int $secondScore, string $date): array
    {
        return [
            'team1_id' => $first, 'team2_id' => $second, 'team1_score' => $firstScore, 'team2_score' => $secondScore,
            'start_date' => $date, 'status' => 'finished', 'bo_type' => 3,
            'team1' => ['id' => $first, 'name' => 'Team '.$first], 'team2' => ['id' => $second, 'name' => 'Team '.$second],
        ];
    }
}
