<?php

namespace Tests\Feature;

use App\Services\LiveMatchMatcher;
use App\Services\OddsApiLiveScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OddsApiLiveScoreServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_enriches_a_cs2_series_and_preserves_bo3_round_score_when_odds_periods_are_binary(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:57:00Z');
        config([
            'services.odds.api_key' => 'odds-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.odds.timeout_seconds' => 10,
        ]);
        Http::fake([
            'https://api.odds-api.io/v3/events/live*' => Http::response([[
                'id' => 987,
                'home' => 'Rooster',
                'away' => 'MARKandLARRY',
                'date' => '2026-08-29T11:00:00Z',
                'status' => 'live',
                'league' => [
                    'name' => 'Counter-Strike Oceania Cup #1 Season 52',
                    'slug' => 'counter-strike-oceania-cup-1-season-52',
                ],
                'scores' => [
                    'home' => 2,
                    'away' => 1,
                    'periods' => [
                        'map1' => ['home' => 1, 'away' => 0],
                        'map2' => ['home' => 1, 'away' => 0],
                    ],
                ],
            ]]),
        ]);

        $service = new OddsApiLiveScoreService(new LiveMatchMatcher);
        $result = $service->enrich([[
            'game' => 'cs',
            'team1' => 'MARKandLARRY',
            'team2' => 'Rooster',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => true,
            'series_score' => '0：0',
            'score' => '9：8',
            'score_source' => 'bo3',
        ]]);

        $this->assertTrue($result[0]['is_live']);
        $this->assertSame('1：2', $result[0]['series_score']);
        $this->assertSame('odds-api', $result[0]['series_score_source']);
        $this->assertSame('987', $result[0]['live_event_id']);
        $this->assertSame('2026-08-29T12:57:00+00:00', $result[0]['live_score_received_at']);
        $this->assertSame('9：8', $result[0]['score']);
        $this->assertSame('bo3', $result[0]['score_source']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/events/live')
            && $request->hasHeader('Cache-Control', 'no-cache, no-store')
            && $request['apiKey'] === 'odds-key'
            && $request['sport'] === 'esports');
    }

    public function test_it_enriches_valorant_series_and_round_scores_from_odds_api_periods(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:57:00Z');
        config([
            'services.odds.api_key' => 'odds-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.odds.timeout_seconds' => 10,
        ]);
        Http::fake([
            'https://api.odds-api.io/v3/events/live*' => Http::response([[
                'id' => 555,
                'home' => 'Sentinels',
                'away' => 'Paper Rex',
                'date' => '2026-08-29T10:00:00Z',
                'status' => 'live',
                'league' => [
                    'name' => 'VALORANT Champions 2026',
                    'slug' => 'valorant-champions-2026',
                ],
                'scores' => [
                    'home' => 1,
                    'away' => 0,
                    'periods' => [
                        'map1' => ['home' => 13, 'away' => 8],
                        'map2' => ['home' => 7, 'away' => 11],
                    ],
                ],
            ]]),
        ]);

        $service = new OddsApiLiveScoreService(new LiveMatchMatcher);
        $result = $service->enrich([[
            'game' => 'valorant',
            'team1' => 'Sentinels',
            'team2' => 'Paper Rex',
            'start_at' => CarbonImmutable::parse('2026-08-29T10:00:00Z'),
            'is_live' => true,
            'series_score' => '0：0',
            'score' => '0：0',
        ]]);

        $this->assertTrue($result[0]['is_live']);
        $this->assertSame('1：0', $result[0]['series_score']);
        $this->assertSame('odds-api', $result[0]['series_score_source']);
        $this->assertSame('7：11', $result[0]['score']);
        $this->assertSame('odds-api', $result[0]['score_source']);
    }

    public function test_it_does_not_request_odds_live_events_for_an_unsupported_game(): void
    {
        config([
            'services.odds.api_key' => 'odds-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
        ]);
        Http::fake();

        $matches = [[
            'game' => 'dota2',
            'team1' => 'Team Spirit',
            'team2' => 'Gaimin Gladiators',
            'start_at' => CarbonImmutable::parse('2026-08-29T10:00:00Z'),
            'is_live' => true,
            'series_score' => '0：1',
            'score' => '7：3',
        ]];

        $result = (new OddsApiLiveScoreService(new LiveMatchMatcher))->enrich($matches);

        $this->assertSame($matches, $result);
        Http::assertNothingSent();
    }
}
