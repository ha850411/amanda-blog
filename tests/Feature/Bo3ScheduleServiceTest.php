<?php

namespace Tests\Feature;

use App\Services\Bo3ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bo3ScheduleServiceTest extends TestCase
{
    public function test_valorant_schedule_merges_the_complete_local_day_from_the_api(): void
    {
        config([
            'services.bo3.base_url' => 'https://bo3.gg',
            'services.bo3.api_url' => 'https://api.bo3.gg/api/v1',
            'services.bo3.timezone' => 'Asia/Taipei',
        ]);

        Http::fake([
            'https://bo3.gg/valorant/matches/current*' => Http::response($this->valorantIncompleteScheduleHtml(), 200),
            'https://api.bo3.gg/api/v1/matches*' => Http::response([
                'total' => ['count' => 3],
                'results' => [
                    $this->valorantApiMatch('joblife-vs-fnatic-1-14-08-2026', '2026-08-14T18:00:00.000+00:00', 'Joblife', 'Fnatic', 'VCT 2026: EMEA Stage 2'),
                    $this->valorantApiMatch('furia-esports-vs-2game-esports-14-08-2026', '2026-08-14T21:00:00.000+00:00', 'FURIA', '2GAME Esports', 'VCT 2026: Americas Stage 2'),
                    $this->valorantApiMatch('cloud9-vs-fluxo-15-08-2026', '2026-08-15T00:00:00.000+00:00', 'Cloud9', 'Fluxo W7M', 'VCT 2026: Americas Stage 2', 'current', 6, 14),
                ],
            ], 200),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate(
            'valorant',
            CarbonImmutable::parse('2026-08-15', 'Asia/Taipei'),
            ['s'],
        );

        $this->assertCount(3, $matches);
        $this->assertSame(['02:00', '05:00', '08:00'], array_map(
            fn (array $match): string => $match['start_at']->format('H:i'),
            $matches,
        ));
        $this->assertSame(['Joblife', 'FURIA', 'Cloud9'], array_column($matches, 'team1'));
        $this->assertFalse($matches[0]['is_live']);
        $this->assertTrue($matches[2]['is_live']);
        $this->assertSame('6：14', $matches[2]['score']);
        $this->assertSame(
            'https://bo3.gg/valorant/matches/joblife-vs-fnatic-1-14-08-2026',
            $matches[0]['url'],
        );

        Http::assertSent(function ($request): bool {
            if (! str_starts_with($request->url(), 'https://api.bo3.gg/api/v1/matches?')) {
                return false;
            }

            return $request['page'] === ['offset' => 0, 'limit' => 100]
                && $request['sort'] === 'start_date'
                && $request['with'] === 'teams,tournament'
                && $request['filter']['matches.discipline_id']['eq'] === 2
                && $request['filter']['matches.start_date']['gt'] === '2026-08-14T15:59:59+00:00'
                && $request['filter']['matches.start_date']['lt'] === '2026-08-15T16:00:00+00:00'
                && $request['filter']['matches.tier']['in'] === 's';
        });
    }

    public function test_live_match_format_is_fetched_from_match_api_when_current_row_omits_it(): void
    {
        config([
            'services.bo3.base_url' => 'https://bo3.gg',
            'services.bo3.timezone' => 'Asia/Taipei',
        ]);

        Http::fake([
            'https://bo3.gg/lol/matches/current*' => Http::response($this->liveScheduleHtml(), 200),
            'https://bo3.gg/api/v1/matches/shifters-vs-sk-gaming-14-08-2026' => Http::response([
                'status' => 'current',
                'bo_type' => 3,
                'team1_score' => 1,
                'team2_score' => 0,
            ], 200),
        ]);

        $matches = app(Bo3ScheduleService::class)->forDate(
            'lol',
            CarbonImmutable::parse('2026-08-14', 'Asia/Taipei'),
        );

        $this->assertCount(1, $matches);
        $this->assertSame('Shifters', $matches[0]['team1']);
        $this->assertSame('SK Gaming', $matches[0]['team2']);
        $this->assertSame('LEC 2026 Summer', $matches[0]['tournament']);
        $this->assertSame('BO3', $matches[0]['format']);
        $this->assertTrue($matches[0]['is_live']);
        $this->assertSame('6：14', $matches[0]['score']);

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://bo3.gg/api/v1/matches/shifters-vs-sk-gaming-14-08-2026');
    }

    private function liveScheduleHtml(): string
    {
        $events = [[
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            'name' => 'Shifters vs SK Gaming',
            'url' => 'https://bo3.gg/lol/matches/shifters-vs-sk-gaming-14-08-2026',
            'startDate' => '2026-08-14T15:00:00.000+00:00',
        ]];

        return '<html><div class="table-row table-row--current">'
            .'<a href="/lol/matches/shifters-vs-sk-gaming-14-08-2026">'
            .'<div class="team-name">Shifters</div>'
            .'<div class="c-match-score">6 - 14</div>'
            .'<div class="team-name">SK Gaming</div>'
            .'</a>'
            .'<p class="tournament-name">LEC 2026 Summer</p>'
            .'</div>'
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }

    private function valorantIncompleteScheduleHtml(): string
    {
        $events = [[
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            'name' => 'Cloud9 vs Fluxo W7M',
            'url' => 'https://bo3.gg/valorant/matches/cloud9-vs-fluxo-15-08-2026',
            'startDate' => '2026-08-15T00:00:00.000+00:00',
        ]];

        return '<html><div class="table-row table-row--upcoming">'
            .'<a href="/valorant/matches/cloud9-vs-fluxo-15-08-2026">'
            .'<div class="team-name">Cloud9</div><div class="bo-type">Bo3</div>'
            .'<div class="team-name">Fluxo W7M</div></a>'
            .'<p class="tournament-name">VCT 2026: Americas Stage 2</p>'
            .'<div class="time">00:00</div></div>'
            .'<script id="micro-markup" type="application/ld+json">'
            .json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script></html>';
    }

    /** @return array<string, mixed> */
    private function valorantApiMatch(
        string $slug,
        string $startDate,
        string $team1,
        string $team2,
        string $tournament,
        ?string $status = null,
        ?int $team1Score = null,
        ?int $team2Score = null,
    ): array {
        return [
            'slug' => $slug,
            'start_date' => $startDate,
            'bo_type' => 3,
            'tier' => 's',
            'status' => $status,
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'team1' => ['name' => $team1],
            'team2' => ['name' => $team2],
            'tournament' => ['name' => $tournament],
        ];
    }
}
