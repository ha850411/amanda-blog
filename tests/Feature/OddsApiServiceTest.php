<?php

namespace Tests\Feature;

use App\Services\OddsApiService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OddsApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.odds.api_key' => 'odds-test-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.odds.bookmakers' => 'Stake,Bet365',
            'services.odds.bookmaker_priority' => 'Stake,Bet365',
        ]);
        Http::preventStrayRequests();
    }

    public function test_nba_english_names_match_and_stake_prices_follow_the_displayed_team_order(): void
    {
        config(['services.odds.bookmakers' => null]);
        $match = $this->match();
        Http::fake([
            '*/events*' => Http::response([$this->event(1, $match)]),
            '*/bookmakers/selected*' => Http::response(['bookmakers' => ['Stake', 'Bet365']]),
            '*/odds/multi*' => Http::response([$this->odds(1, [
                'Stake' => [$this->moneyline('1.70', '2.11')],
                'Bet365' => [$this->moneyline('1.80', '2.20')],
            ])]),
        ]);
        $result = $this->enrich([$match]);
        $this->assertSame('紐約尼克', $result[0]['team1']);
        $this->assertSame(['price' => 2.11, 'bookmaker' => 'Stake'], $result[0]['odds']['team1']);
        $this->assertSame(['price' => 1.70, 'bookmaker' => 'Stake'], $result[0]['odds']['team2']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/events')
            && $r['sport'] === 'basketball' && $r['league'] === 'usa-nba'
            && $r['from'] === '2026-11-15T16:00:00+00:00' && $r['to'] === '2026-11-22T15:59:59+00:00');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/odds/multi')
            && $r['bookmakers'] === 'Stake,Bet365' && $r['eventIds'] === '1');
        Http::assertSentCount(3);
    }

    public function test_reversed_provider_order_and_la_alias_do_not_swap_prices(): void
    {
        $match = array_replace($this->match(), ['team2' => '洛杉磯快艇', 'team2_name' => 'LA Clippers']);
        $event = array_replace($this->event(1, $match), ['home' => 'New York Knicks', 'away' => 'Los Angeles Clippers']);
        Http::fake([
            '*/events*' => Http::response([$event]),
            '*/odds/multi*' => Http::response([$this->odds(1, ['Stake' => [$this->moneyline('1.60', '2.40')]])]),
        ]);
        $result = $this->enrich([$match]);
        $this->assertSame(1.60, $result[0]['odds']['team1']['price']);
        $this->assertSame(2.40, $result[0]['odds']['team2']['price']);
    }

    public function test_fallback_requires_complete_two_way_moneyline_from_one_bookmaker(): void
    {
        $matches = array_map(fn (int $day): array => $this->match($day), range(16, 20));
        $events = array_map(fn (array $match, int $index): array => $this->event($index + 1, $match), $matches, array_keys($matches));
        Http::fake([
            '*/events*' => Http::response($events),
            '*/odds/multi*' => Http::response([
                $this->odds(1, ['Stake' => [$this->moneyline('1.70', null)], 'Bet365' => [$this->moneyline('1.80', '2.20')]]),
                $this->odds(2, ['Stake' => [$this->moneyline('1.70', null)], 'Bet365' => [$this->moneyline(null, '2.20')]]),
                $this->odds(3, ['Stake' => [array_replace($this->moneyline('1.70', '2.11'), ['name' => 'ML HT'])]]),
                $this->odds(4, ['Stake' => [['name' => 'ML', 'odds' => [['home' => '1.70', 'away' => '2.11', 'draw' => '15']]]]]),
                $this->odds(5, ['Stake' => [$this->moneyline('1', '2.11')], 'Bet365' => [$this->moneyline('0', '2.20')]]),
            ]),
        ]);
        $result = $this->enrich($matches);
        $this->assertSame(['price' => 2.20, 'bookmaker' => 'Bet365'], $result[0]['odds']['team1']);
        $this->assertSame(['price' => 1.80, 'bookmaker' => 'Bet365'], $result[0]['odds']['team2']);
        $this->assertSame([null, null, null, null], array_column(array_slice($result, 1), 'odds'));
    }

    public function test_nba_rejects_other_opponents_leagues_dates_and_missing_english_names(): void
    {
        $match = array_replace($this->match(), ['team2' => '洛杉磯湖人', 'team2_name' => 'Los Angeles Lakers']);
        $base = $this->event(1, $match);
        Http::fake(['*/events*' => Http::response([
            array_replace($base, ['id' => 2, 'home' => 'Los Angeles Clippers']),
            array_replace($base, ['id' => 3, 'league' => ['slug' => 'nba-summer-league']]),
            array_replace($base, ['id' => 4, 'sport' => ['slug' => 'esports']]),
            array_replace($base, ['id' => 5, 'date' => '2026-11-17T01:00:00Z']),
            array_replace($base, ['id' => 6, 'date' => 'invalid date']),
            array_replace($base, ['id' => 7, 'home' => '洛杉磯湖人']),
        ])]);
        $missingNames = $match;
        unset($missingNames['team1_name'], $missingNames['team2_name']);
        $result = $this->enrich([$match, $missingNames]);
        $this->assertSame([null, null], array_column($result, 'odds'));
        Http::assertSentCount(1);
    }

    public function test_mixed_schedule_uses_separate_event_feeds_and_keeps_original_indexes(): void
    {
        $nba = $this->match();
        $esport = array_replace($nba, ['game' => 'cs', 'team1' => $nba['team1_name'], 'team2' => $nba['team2_name']]);
        Http::fake([
            '*/events*' => fn (Request $r) => Http::response([
                $r['sport'] === 'basketball' ? $this->event(1, $nba) : array_replace($this->event(2, $nba), ['sport' => ['slug' => 'esports']]),
            ]),
            '*/odds/multi*' => Http::response([
                $this->odds(1, ['Stake' => [$this->moneyline('1.70', '2.11')]]),
                $this->odds(2, ['Stake' => [$this->moneyline('1.20', '5.50')]]),
            ]),
        ]);
        $result = $this->enrich([3 => $nba, 5 => $esport, 8 => array_replace($nba, ['game' => 'mlb'])]);
        $this->assertSame([3, 5, 8], array_keys($result));
        $this->assertSame(2.11, $result[3]['odds']['team1']['price']);
        $this->assertSame(5.50, $result[5]['odds']['team1']['price']);
        $this->assertNull($result[8]['odds']);
        Http::assertSent(fn (Request $r): bool => ($r['sport'] ?? null) === 'esports' && ! isset($r['league']));
        Http::assertSentCount(3);
    }

    public function test_a_failed_nba_feed_does_not_remove_esports_odds(): void
    {
        $nba = $this->match();
        $esport = array_replace($nba, ['game' => 'cs', 'team1' => $nba['team1_name'], 'team2' => $nba['team2_name']]);
        Http::fake([
            '*/events*' => fn (Request $r) => $r['sport'] === 'basketball' ? Http::failedConnection() : Http::response([$this->event(2, $esport)]),
            '*/odds/multi*' => Http::response([$this->odds(2, ['Stake' => [$this->moneyline('1.70', '2.11')]])]),
        ]);
        $result = $this->enrich([$nba, $esport]);
        $this->assertNull($result[0]['odds']);
        $this->assertSame(2.11, $result[1]['odds']['team1']['price']);
    }

    public function test_nineteen_visible_games_are_batched_and_duplicate_events_are_requested_once(): void
    {
        config(['services.odds.bookmakers' => null]);
        [$matches, $events] = $this->manyMatches();
        Http::fake([
            '*/events*' => Http::response($events),
            '*/bookmakers/selected*' => Http::response(['bookmakers' => ['Stake']]),
            '*/odds/multi*' => fn (Request $r) => Http::response(array_map(
                fn (string $id): array => $this->odds((int) $id, ['Stake' => [$this->moneyline('1.70', '2.11')]]),
                explode(',', $r['eventIds']),
            )),
        ]);
        $matches[] = $matches[0];
        $result = $this->enrich($matches);
        $this->assertCount(20, array_filter(array_column($result, 'odds')));
        $requests = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/odds/multi'));
        $this->assertSame([10, 9], $requests->map(fn (array $pair): int => count(explode(',', $pair[0]['eventIds'])))->values()->all());
        $ids = $requests->flatMap(fn (array $pair): array => explode(',', $pair[0]['eventIds']))->all();
        $this->assertSame(array_map('strval', range(1, 19)), $ids);
        Http::assertSentCount(4);
    }

    public function test_a_failed_odds_batch_keeps_other_batches(): void
    {
        [$matches, $events] = $this->manyMatches();
        Http::fake([
            '*/events*' => Http::response($events),
            '*/odds/multi*' => fn (Request $r) => str_starts_with($r['eventIds'], '1,') ? Http::response([], 503) : Http::response(array_map(
                fn (string $id): array => $this->odds((int) $id, ['Stake' => [$this->moneyline('1.70', '2.11')]]),
                explode(',', $r['eventIds']),
            )),
        ]);
        $result = $this->enrich($matches);
        $this->assertSame(array_fill(0, 10, null), array_column(array_slice($result, 0, 10), 'odds'));
        $this->assertCount(9, array_filter(array_column(array_slice($result, 10), 'odds')));
    }

    public function test_refresh_uses_new_prices_without_cache(): void
    {
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('put')->never();
        $match = $this->match();
        Http::fake([
            '*/events*' => Http::response([$this->event(1, $match)]),
            '*/odds/multi*' => Http::sequence()
                ->push([$this->odds(1, ['Stake' => [$this->moneyline('1.70', '2.11')]])])
                ->push([$this->odds(1, ['Stake' => [$this->moneyline('1.60', '2.31')]])]),
        ]);
        $service = app(OddsApiService::class);
        $this->assertSame(2.11, $service->enrich([$match], $match['start_at'])[0]['odds']['team1']['price']);
        $this->assertSame(2.31, $service->enrich([$match], $match['start_at'])[0]['odds']['team1']['price']);
        Http::assertSentCount(4);
    }

    public function test_disabled_odds_and_unavailable_nba_games_skip_requests(): void
    {
        $match = $this->match();
        $result = $this->enrich([
            array_replace($match, ['game' => 'mlb']),
            array_replace($match, ['is_finished' => true]),
            array_replace($match, ['is_cancelled' => true]),
            array_replace($match, ['time_tbd' => true]),
        ]);
        $this->assertSame([null, null, null, null], array_column($result, 'odds'));
        config(['services.odds.api_key' => null]);
        $this->assertNull($this->enrich([$match])[0]['odds']);
        Http::assertNothingSent();
    }

    private function enrich(array $matches): array
    {
        return app(OddsApiService::class)->enrich($matches, CarbonImmutable::parse('2026-11-16', 'Asia/Taipei'), CarbonImmutable::parse('2026-11-22', 'Asia/Taipei'));
    }

    private function match(int $day = 16): array
    {
        return [
            'game' => 'nba', 'team1' => '紐約尼克', 'team2' => '波士頓塞爾提克',
            'team1_name' => 'New York Knicks', 'team2_name' => 'Boston Celtics',
            'start_at' => CarbonImmutable::parse('2026-11-'.$day.'T01:00:00Z'), 'odds' => null,
        ];
    }

    private function event(int $id, array $match): array
    {
        return [
            'id' => $id, 'home' => $match['team2_name'], 'away' => $match['team1_name'],
            'date' => $match['start_at']->toIso8601String(), 'status' => 'pending',
            'sport' => ['slug' => 'basketball'], 'league' => ['slug' => 'usa-nba'],
        ];
    }

    private function moneyline(?string $home, ?string $away): array
    {
        return ['name' => 'ML', 'odds' => [['home' => $home, 'away' => $away]]];
    }

    private function odds(int $id, array $bookmakers): array
    {
        return ['id' => $id, 'bookmakers' => $bookmakers];
    }

    private function manyMatches(): array
    {
        $matches = array_map(fn (int $id): array => array_replace($this->match(), [
            'team1_name' => 'Away '.$id, 'team2_name' => 'Home '.$id,
        ]), range(1, 19));
        $events = array_map(fn (array $match, int $index): array => $this->event($index + 1, $match), $matches, array_keys($matches));

        return [$matches, $events];
    }
}
