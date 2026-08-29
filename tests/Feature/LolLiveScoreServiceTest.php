<?php

namespace Tests\Feature;

use App\Services\LiveMatchMatcher;
use App\Services\LolLiveScoreService;
use App\Services\OddsApiLiveScoreService;
use App\Services\RiotEsportsLiveScoreService;
use App\Services\VlrLiveScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LolLiveScoreServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_fetches_fresh_series_and_riot_current_game_scores_on_every_call(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:25:55Z');
        config([
            'services.odds.api_key' => 'odds-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.riot_esports.schedule_url' => 'https://lolesports.com/en-US/',
            'services.riot_esports.feed_base_url' => 'https://feed.lolesports.com/livestats/v1',
            'services.riot_esports.timeout_seconds' => 8,
            'services.riot_esports.feed_delay_seconds' => 20,
        ]);

        $oddsCalls = 0;
        $scheduleCalls = 0;
        $feedCalls = 0;
        Http::fake(function ($request) use (&$oddsCalls, &$scheduleCalls, &$feedCalls) {
            if (str_contains($request->url(), 'odds-api.io/v3/events/live')) {
                $oddsCalls++;

                return Http::response([[
                    'id' => 777,
                    'home' => 'Team Beta',
                    'away' => 'Team Alpha',
                    'date' => '2026-08-29T08:00:00Z',
                    'status' => 'live',
                    'scores' => [
                        'home' => 0,
                        'away' => $oddsCalls,
                        'periods' => [],
                    ],
                ]]);
            }

            if ($request->url() === 'https://lolesports.com/en-US/') {
                $scheduleCalls++;

                return Http::response('<script>'.$this->riotEvent().'</script>');
            }

            if (str_contains($request->url(), 'feed.lolesports.com/livestats/v1/window/999')) {
                $feedCalls++;

                return Http::response($this->riotWindow(
                    alphaKills: 6 + $feedCalls,
                    betaKills: 3 + $feedCalls,
                ));
            }

            return Http::response([], 404);
        });

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new RiotEsportsLiveScoreService($matcher),
            new VlrLiveScoreService($matcher),
        );
        $matches = [$this->match()];

        $first = $service->enrich($matches);
        $second = $service->enrich($first);

        $this->assertTrue($second[0]['is_live']);
        $this->assertSame('2：0', $second[0]['series_score']);
        $this->assertSame('odds-api', $second[0]['series_score_source']);
        $this->assertSame('8：5', $second[0]['score']);
        $this->assertSame('riot-esports', $second[0]['score_source']);
        $this->assertSame('999', $second[0]['live_game_id']);
        $this->assertSame(4, $second[0]['live_game_number']);
        $this->assertSame('2026-08-29T12:25:39.943Z', $second[0]['live_frame_at']);
        $this->assertSame(2, $oddsCalls);
        $this->assertSame(2, $scheduleCalls);
        $this->assertSame(2, $feedCalls);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/events/live')
            && $request->hasHeader('Cache-Control', 'no-cache, no-store')
            && $request['apiKey'] === 'odds-key'
            && $request['sport'] === 'esports');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://lolesports.com/en-US/'
            && $request->hasHeader('Cache-Control', 'no-cache, no-store'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/window/999')
            && $request->hasHeader('Cache-Control', 'no-cache, no-store')
            && $request['startingTime'] === '2026-08-29T12:25:30.000Z');
    }

    public function test_it_keeps_bo3_fallback_values_when_riot_live_data_is_unavailable(): void
    {
        config([
            'services.odds.api_key' => null,
            'services.riot_esports.schedule_url' => 'https://lolesports.com/en-US/',
        ]);
        Http::fake([
            'https://lolesports.com/en-US/' => Http::response('<html></html>'),
        ]);

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new RiotEsportsLiveScoreService($matcher),
            new VlrLiveScoreService($matcher),
        );

        $matches = [$this->match()];
        $result = $service->enrich($matches);

        $this->assertSame($matches, $result);
        Http::assertSentCount(1);
    }

    public function test_it_uses_riot_series_score_when_odds_live_score_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:25:55Z');
        config([
            'services.odds.api_key' => null,
            'services.riot_esports.schedule_url' => 'https://lolesports.com/en-US/',
            'services.riot_esports.feed_base_url' => 'https://feed.lolesports.com/livestats/v1',
            'services.riot_esports.feed_delay_seconds' => 20,
        ]);
        Http::fake([
            'https://lolesports.com/en-US/' => Http::response('<script>'.$this->riotEvent().'</script>'),
            'https://feed.lolesports.com/livestats/v1/window/999*' => Http::response(
                $this->riotWindow(alphaKills: 7, betaKills: 4),
            ),
        ]);

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new RiotEsportsLiveScoreService($matcher),
            new VlrLiveScoreService($matcher),
        );

        $result = $service->enrich([$this->match()]);

        $this->assertSame('1：0', $result[0]['series_score']);
        $this->assertSame('riot-esports', $result[0]['series_score_source']);
        $this->assertSame('7：4', $result[0]['score']);
    }

    public function test_it_marks_a_stale_bo3_live_match_as_completed_from_riot(): void
    {
        config([
            'services.odds.api_key' => null,
            'services.riot_esports.schedule_url' => 'https://lolesports.com/en-US/',
        ]);
        $event = json_decode($this->riotEvent(), true, flags: JSON_THROW_ON_ERROR);
        $event['state'] = 'completed';
        $event['match']['state'] = 'completed';
        $event['match']['games'][3]['state'] = 'completed';
        $event['matchTeams'][0]['result'] = ['gameWins' => 3, 'outcome' => 'win'];
        $event['matchTeams'][1]['result'] = ['gameWins' => 1, 'outcome' => 'loss'];

        Http::fake([
            'https://lolesports.com/en-US/' => Http::response(
                '<script>'.json_encode($event, JSON_THROW_ON_ERROR).'</script>',
            ),
        ]);

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new RiotEsportsLiveScoreService($matcher),
            new VlrLiveScoreService($matcher),
        );

        $result = $service->enrich([$this->match()]);

        $this->assertFalse($result[0]['is_live']);
        $this->assertSame('riot-esports', $result[0]['live_status_source']);
        $this->assertSame('3：1', $result[0]['series_score']);
        $this->assertSame('riot-esports', $result[0]['series_score_source']);
        Http::assertSentCount(1);
    }

    public function test_it_enriches_valorant_matches_through_vlr(): void
    {
        config([
            'services.odds.api_key' => null,
            'services.riot_esports.schedule_url' => 'https://lolesports.com/en-US/',
            'services.vlr.matches_url' => 'https://www.vlr.gg/matches',
        ]);

        $vlrMatches = <<<'HTML'
<div class="wf-card">
    <a href="/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2" class="wf-module-item match-item mod-color mod-first">
        <div class="match-item-vs">
            <div class="match-item-vs-team">
                <div class="match-item-vs-team-name">Gen.G</div>
                <div class="match-item-vs-team-score">0</div>
            </div>
            <div class="match-item-vs-team">
                <div class="match-item-vs-team-name">T1</div>
                <div class="match-item-vs-team-score">1</div>
            </div>
        </div>
        <div class="match-item-eta">
            <div class="ml mod-live"><div class="ml-status">LIVE</div></div>
        </div>
    </a>
</div>
HTML;

        $vlrDetail = <<<'HTML'
<div class="match-header-vs">
    <div class="match-header-vs-score">
        <div class="sp-hide"><span>0</span><span class="colon">:</span><span>1</span></div>
    </div>
</div>
<div class="vm-stats-game" data-game-id="1"><div class="score">5</div><div class="score">13</div></div>
<div class="vm-stats-game mod-active" data-game-id="2"><div class="score">11</div><div class="score">12</div></div>
HTML;

        Http::fake([
            'https://www.vlr.gg/matches' => Http::response($vlrMatches),
            'https://www.vlr.gg/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2' => Http::response($vlrDetail),
        ]);

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new RiotEsportsLiveScoreService($matcher),
            new VlrLiveScoreService($matcher),
        );

        $matches = [[
            'game' => 'valorant',
            'team1' => 'Gen.G Esports',
            'team2' => 'T1',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => true,
        ]];

        $result = $service->enrich($matches);

        $this->assertTrue($result[0]['is_live']);
        $this->assertSame('0：1', $result[0]['series_score']);
        $this->assertSame('vlr', $result[0]['series_score_source']);
        $this->assertSame('11：12', $result[0]['score']);
        $this->assertSame('vlr', $result[0]['score_source']);
    }

    /** @return array<string, mixed> */
    private function match(): array
    {
        return [
            'game' => 'lol',
            'team1' => 'Team Alpha',
            'team2' => 'Team Beta',
            'start_at' => CarbonImmutable::parse('2026-08-29T08:00:00Z'),
            'is_live' => true,
            'series_score' => '0：0',
            'score' => '0：0',
        ];
    }

    private function riotEvent(): string
    {
        return json_encode([
            '__typename' => 'EventMatch',
            'id' => '888',
            'blockName' => 'Playoffs',
            'startTime' => '2026-08-29T08:00:00Z',
            'state' => 'completed',
            'type' => 'match',
            'matchTeams' => [
                [
                    'id' => '888:111',
                    'name' => 'Team Alpha',
                    'code' => 'ALP',
                    'result' => ['gameWins' => 1, 'outcome' => null],
                ],
                [
                    'id' => '888:222',
                    'name' => 'Team Beta',
                    'code' => 'BET',
                    'result' => ['gameWins' => 0, 'outcome' => null],
                ],
            ],
            'match' => [
                'id' => '888',
                'state' => 'inProgress',
                'games' => [
                    ['id' => '996', 'number' => 1, 'state' => 'completed'],
                    ['id' => '997', 'number' => 2, 'state' => 'completed'],
                    ['id' => '998', 'number' => 3, 'state' => 'completed'],
                    ['id' => '999', 'number' => 4, 'state' => 'inProgress'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function riotWindow(int $alphaKills, int $betaKills): array
    {
        return [
            'esportsGameId' => '999',
            'esportsMatchId' => '888',
            'gameMetadata' => [
                'blueTeamMetadata' => ['esportsTeamId' => '222'],
                'redTeamMetadata' => ['esportsTeamId' => '111'],
            ],
            'frames' => [[
                'rfc460Timestamp' => '2026-08-29T12:25:39.943Z',
                'gameState' => 'in_game',
                'blueTeam' => ['totalKills' => $betaKills],
                'redTeam' => ['totalKills' => $alphaKills],
            ]],
        ];
    }
}
