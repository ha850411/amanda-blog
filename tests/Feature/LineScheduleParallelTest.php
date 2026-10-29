<?php

namespace Tests\Feature;

use App\Services\Bo3HeadToHeadService;
use App\Services\Bo3ScheduleService;
use App\Services\LineScheduleBot;
use App\Services\MlbScheduleService;
use App\Services\NbaScheduleService;
use App\Services\OddsApiService;
use App\Services\RecentForm;
use App\Services\ScheduleHttp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineScheduleParallelTest extends TestCase
{
    public function test_parallel_results_keep_match_order_history_details_and_odds_on_the_correct_rows(): void
    {
        config(['services.bo3.timezone' => 'Asia/Taipei']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00:00', 'Asia/Taipei'));
        Http::preventStrayRequests();
        Http::fake(['https://example.test/*' => Http::response([])]);
        $rows = [];
        foreach (['lol', 'mlb', 'nba'] as $index => $game) {
            $rows[$game] = [
                'game' => $game, 'name' => $game.' Alpha vs Beta',
                'team1' => $game.' Alpha', 'team2' => $game.' Beta',
                'tournament' => 'Test Cup', 'format' => 'BO3',
                'start_at' => CarbonImmutable::parse('2026-10-29 12:00:00', 'Asia/Taipei')->addHours($index),
                'url' => 'https://example.test/'.$game,
            ];
        }
        $this->partialMock(Bo3ScheduleService::class, function ($mock) use ($rows): void {
            $mock->shouldReceive('forRange')->once()->andReturn(['matches' => [$rows['lol']], 'failed_requests' => []]);
            $mock->shouldReceive('enrichLiveDetailsAndMissingFormats')->once()->andReturnUsing(function (array $matches): array {
                foreach ($matches as &$match) {
                    $match['format'] = 'BO5';
                }

                return $matches;
            });
        });
        $h2h = ['sample_size' => 1, 'team1_wins' => 1, 'team2_wins' => 0, 'team1_games' => 2, 'team2_games' => 0, 'series' => []];
        foreach ([Bo3HeadToHeadService::class => 'lol', MlbScheduleService::class => 'mlb', NbaScheduleService::class => 'nba'] as $service => $game) {
            $this->partialMock($service, function ($mock) use ($game, $rows, $h2h): void {
                if ($game !== 'lol') {
                    $mock->shouldReceive('forRange')->once()->andReturn([$rows[$game]]);
                }
                $mock->shouldReceive($game === 'lol' ? 'enrich' : 'enrichRecentForm')->once()->andReturnUsing(function (array $matches) use ($game, $h2h): array {
                    ScheduleHttp::pool(fn (Pool $pool) => $pool->as('history')->get('https://example.test/history/'.$game));
                    foreach ($matches as &$match) {
                        $form = RecentForm::fromMatches([[
                            'date' => '2026/10/27', 'opponent' => $game.' Rival', 'format' => '單場',
                            'team_score' => 2, 'opponent_score' => 1, 'result' => 'W',
                        ]]);
                        $match['recent_form'] = ['team1' => $form, 'team2' => $form];
                        $match['h2h'] = $game === 'lol' ? $h2h : null;
                    }

                    return $matches;
                });
            });
        }
        $this->mock(OddsApiService::class, function ($mock): void {
            $mock->shouldReceive('enrich')->once()->andReturnUsing(function (array $matches): array {
                ScheduleHttp::pool(fn (Pool $pool) => $pool->as('odds')->get('https://example.test/odds'));
                foreach ($matches as $index => &$match) {
                    $match['odds'] = [
                        'team1' => ['price' => 2.0 + $index, 'bookmaker' => 'Test'],
                        'team2' => ['price' => 1.8, 'bookmaker' => 'Test'],
                    ];
                }

                return $matches;
            });
        });

        try {
            $reply = app(LineScheduleBot::class)->reply('!match 明天 game=lol/mlb/nba');
            $this->assertSame(['lol', 'mlb', 'nba'], array_column($reply->imageData['matches'], 'game'));
            foreach ($reply->imageData['matches'] as $index => $match) {
                $this->assertSame(2.0 + $index, $match['odds']['team1']['price']);
                $this->assertSame($match['game'].' Rival', $match['recent_form']['team1']['matches'][0]['opponent']);
            }
            $this->assertSame('BO5', $reply->imageData['matches'][0]['format']);
            $this->assertSame($h2h, $reply->imageData['matches'][0]['h2h']);
            Http::assertSentCount(4);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
