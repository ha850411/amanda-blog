<?php

namespace Tests\Feature;

use App\Services\LiveMatchMatcher;
use App\Services\LolLiveScoreService;
use App\Services\OddsApiLiveScoreService;
use App\Services\PandaScoreFrameStream;
use App\Services\PandaScoreLiveScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class LolLiveScoreServiceTest extends TestCase
{
    public function test_it_fetches_fresh_series_and_current_game_scores_on_every_call(): void
    {
        config([
            'services.odds.api_key' => 'odds-key',
            'services.odds.base_url' => 'https://api.odds-api.io/v3',
            'services.pandascore.api_token' => 'panda-token',
            'services.pandascore.base_url' => 'https://api.pandascore.co',
        ]);

        $oddsCalls = 0;
        $pandaCalls = 0;
        Http::fake(function ($request) use (&$oddsCalls, &$pandaCalls) {
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

            if (str_contains($request->url(), 'api.pandascore.co/lives')) {
                $pandaCalls++;

                return Http::response([[
                    'endpoints' => [[
                        'match_id' => 888,
                        'open' => true,
                        'type' => 'frames',
                        'url' => 'wss://live.pandascore.co/matches/888',
                    ]],
                    'match' => [
                        'id' => 888,
                        'date' => '2026-08-29T08:00:00Z',
                        'opponents' => [
                            ['opponent' => ['name' => 'Team Alpha']],
                            ['opponent' => ['name' => 'Team Beta']],
                        ],
                    ],
                ]]);
            }

            return Http::response([], 404);
        });

        $frames = Mockery::mock(PandaScoreFrameStream::class);
        $frames->shouldReceive('firstFrame')
            ->twice()
            ->with('wss://live.pandascore.co/matches/888', 'panda-token')
            ->andReturn(
                $this->frame(alphaKills: 7, betaKills: 4),
                $this->frame(alphaKills: 8, betaKills: 5),
            );

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new PandaScoreLiveScoreService($matcher, $frames),
        );
        $matches = [$this->match()];

        $first = $service->enrich($matches);
        $second = $service->enrich($first);

        $this->assertTrue($second[0]['is_live']);
        $this->assertSame('2：0', $second[0]['series_score']);
        $this->assertSame('odds-api', $second[0]['series_score_source']);
        $this->assertSame('8：5', $second[0]['score']);
        $this->assertSame('pandascore', $second[0]['score_source']);
        $this->assertSame(2, $oddsCalls);
        $this->assertSame(2, $pandaCalls);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/events/live')
            && $request->hasHeader('Cache-Control', 'no-cache, no-store')
            && $request['apiKey'] === 'odds-key'
            && $request['sport'] === 'esports');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/lives')
            && $request->hasHeader('Authorization', 'Bearer panda-token')
            && $request->hasHeader('Cache-Control', 'no-cache, no-store')
            && $request['per_page'] === 100);
    }

    public function test_it_keeps_bo3_fallback_values_when_live_credentials_are_missing(): void
    {
        config([
            'services.odds.api_key' => null,
            'services.pandascore.api_token' => null,
        ]);
        Http::fake();

        $matcher = new LiveMatchMatcher;
        $service = new LolLiveScoreService(
            new OddsApiLiveScoreService($matcher),
            new PandaScoreLiveScoreService($matcher, Mockery::mock(PandaScoreFrameStream::class)),
        );

        $matches = [$this->match()];
        $result = $service->enrich($matches);

        $this->assertSame($matches, $result);
        Http::assertNothingSent();
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

    /** @return array<string, mixed> */
    private function frame(int $alphaKills, int $betaKills): array
    {
        return [
            'match' => ['id' => 888],
            'game' => ['id' => 999, 'finished' => false, 'winner_id' => null],
            'blue' => [
                'id' => 2,
                'name' => 'Team Beta',
                'kills' => $betaKills,
                'score' => 0,
            ],
            'red' => [
                'id' => 1,
                'name' => 'Team Alpha',
                'kills' => $alphaKills,
                'score' => 1,
            ],
            'current_timestamp' => 532,
        ];
    }
}
