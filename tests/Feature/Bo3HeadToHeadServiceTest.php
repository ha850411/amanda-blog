<?php

namespace Tests\Feature;

use App\Services\Bo3HeadToHeadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bo3HeadToHeadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bo3.api_url' => 'https://api.bo3.gg/api/v1',
            'services.bo3.timezone' => 'Asia/Taipei',
        ]);
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 12:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_summarizes_the_latest_api_head_to_head_results_in_scheduled_team_order(): void
    {
        Http::fake([
            'https://api.bo3.gg/api/v1/matches/blg-vs-we' => Http::response([
                'team1_id' => 17842,
                'team2_id' => 17801,
                'discipline_id' => 3,
            ]),
            'https://api.bo3.gg/api/v1/matches*' => Http::response([
                'total' => ['count' => 15],
                'results' => [
                    $this->h2hResult(17801, 17842, 0, 2, '2026-08-10T12:00:00+00:00', 3),
                    $this->h2hResult(17801, 17842, 2, 3, '2026-07-20T12:00:00+00:00', 5),
                    $this->h2hResult(17842, 17801, 1, 3, '2026-06-03T12:00:00+00:00', 5),
                    $this->h2hResult(17801, 17842, 0, 2, '2026-05-18T12:00:00+00:00', 3),
                    $this->h2hResult(17801, 17842, 0, 2, '2026-04-02T12:00:00+00:00', 3),
                ],
            ]),
        ]);

        $matches = app(Bo3HeadToHeadService::class)->enrich([[
            'game' => 'lol',
            'team1' => 'Bilibili Gaming',
            'team2' => 'Team WE',
            'url' => 'https://bo3.gg/lol/matches/blg-vs-we',
        ]]);

        $this->assertSame([
            'sample_size' => 5,
            'history_total' => 15,
            'team1_wins' => 4,
            'team2_wins' => 1,
            'team1_games' => 10,
            'team2_games' => 5,
            'series' => [
                ['date' => '2026/08/10', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
                ['date' => '2026/07/20', 'format' => 'BO5', 'team1_score' => 3, 'team2_score' => 2, 'winner' => 'team1'],
                ['date' => '2026/06/03', 'format' => 'BO5', 'team1_score' => 1, 'team2_score' => 3, 'winner' => 'team2'],
                ['date' => '2026/05/18', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
                ['date' => '2026/04/02', 'format' => 'BO3', 'team1_score' => 2, 'team2_score' => 0, 'winner' => 'team1'],
            ],
        ], $matches[0]['h2h']);

        Http::assertSent(function ($request): bool {
            if (! str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches?')) {
                return false;
            }

            return $request['page'] === ['offset' => 0, 'limit' => 5]
                && $request['sort'] === '-start_date'
                && $request['filter']['matches.status']['in'] === 'finished'
                && $request['filter']['matches.team_ids']['contains'] === '17842,17801'
                && $request['filter']['matches.start_date']['lt'] === '2026-08-14'
                && $request['filter']['matches.discipline_id']['eq'] === 3
                && ! isset($request['with']);
        });
    }

    public function test_it_enriches_valorant_head_to_head_results(): void
    {
        Http::fake([
            'https://api.bo3.gg/api/v1/matches/drx-vs-zeta-division-15-08-2026' => Http::response([
                'team1_id' => 6916,
                'team2_id' => 7030,
                'discipline_id' => 2,
            ]),
            'https://api.bo3.gg/api/v1/matches*' => Http::response([
                'total' => ['count' => 4],
                'results' => [
                    $this->h2hResult(6916, 7030, 2, 1, '2026-02-07T08:00:00+00:00', 3),
                    $this->h2hResult(7030, 6916, 0, 2, '2024-12-19T12:10:00+00:00', 3),
                    $this->h2hResult(7030, 6916, 1, 2, '2024-06-15T08:00:00+00:00', 3),
                    $this->h2hResult(7030, 6916, 0, 2, '2023-03-25T09:00:00+00:00', 3),
                ],
            ]),
        ]);

        $matches = app(Bo3HeadToHeadService::class)->enrich([[
            'game' => 'valorant',
            'team1' => 'KIWOOM DRX',
            'team2' => 'ZETA DIVISION',
            'url' => 'https://bo3.gg/valorant/matches/drx-vs-zeta-division-15-08-2026',
        ]]);

        $this->assertSame(4, $matches[0]['h2h']['sample_size']);
        $this->assertSame(4, $matches[0]['h2h']['team1_wins']);
        $this->assertSame(0, $matches[0]['h2h']['team2_wins']);
        $this->assertSame(8, $matches[0]['h2h']['team1_games']);
        $this->assertSame(2, $matches[0]['h2h']['team2_games']);
        $this->assertCount(4, $matches[0]['h2h']['series']);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches?')
            && $request['filter']['matches.discipline_id']['eq'] === 2
            && $request['filter']['matches.team_ids']['contains'] === '6916,7030');
    }

    public function test_it_leaves_unsupported_and_unavailable_head_to_head_data_optional(): void
    {
        Http::fake([
            'https://api.bo3.gg/api/v1/matches/lol-without-detail' => Http::response([], 503),
        ]);

        $matches = app(Bo3HeadToHeadService::class)->enrich([
            [
                'game' => 'cs',
                'url' => 'https://bo3.gg/matches/cs-match',
            ],
            [
                'game' => 'lol',
                'url' => 'https://bo3.gg/lol/matches/lol-without-detail',
            ],
        ]);

        $this->assertNull($matches[0]['h2h']);
        $this->assertNull($matches[1]['h2h']);
        Http::assertSentCount(1);
    }

    /** @return array<string, int|string> */
    private function h2hResult(
        int $team1Id,
        int $team2Id,
        int $team1Score,
        int $team2Score,
        string $startDate,
        int $boType,
    ): array {
        return [
            'status' => 'finished',
            'team1_id' => $team1Id,
            'team2_id' => $team2Id,
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'start_date' => $startDate,
            'bo_type' => $boType,
        ];
    }
}
