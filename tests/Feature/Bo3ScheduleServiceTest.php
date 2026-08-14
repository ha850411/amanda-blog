<?php

namespace Tests\Feature;

use App\Services\Bo3ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Bo3ScheduleServiceTest extends TestCase
{
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
}
