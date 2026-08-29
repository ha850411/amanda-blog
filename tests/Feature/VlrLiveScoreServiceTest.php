<?php

namespace Tests\Feature;

use App\Services\LiveMatchMatcher;
use App\Services\VlrLiveScoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VlrLiveScoreServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_enriches_valorant_live_series_and_active_round_score_from_vlr(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:00:00Z');
        config([
            'services.vlr.matches_url' => 'https://www.vlr.gg/matches',
            'services.vlr.base_url' => 'https://www.vlr.gg',
            'services.vlr.timeout_seconds' => 8,
        ]);

        Http::fake([
            'https://www.vlr.gg/matches' => Http::response($this->vlrMatchesListHtml()),
            'https://www.vlr.gg/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2' => Http::response($this->vlrMatchDetailHtml()),
        ]);

        $service = new VlrLiveScoreService(new LiveMatchMatcher);
        $matches = [[
            'game' => 'valorant',
            'team1' => 'Gen.G Esports',
            'team2' => 'T1',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => false,
            'series_score' => null,
            'score' => null,
        ]];

        $result = $service->enrich($matches);

        $this->assertTrue($result[0]['is_live']);
        $this->assertSame('0：1', $result[0]['series_score']);
        $this->assertSame('vlr', $result[0]['series_score_source']);
        $this->assertSame('11：12', $result[0]['score']);
        $this->assertSame('vlr', $result[0]['score_source']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.vlr.gg/matches'
            && $request->hasHeader('Cache-Control', 'no-cache, no-store'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.vlr.gg/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2'
            && $request->hasHeader('Cache-Control', 'no-cache, no-store'));
    }

    public function test_it_handles_reversed_team_order_when_matching_vlr(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T12:00:00Z');
        config([
            'services.vlr.matches_url' => 'https://www.vlr.gg/matches',
            'services.vlr.base_url' => 'https://www.vlr.gg',
            'services.vlr.timeout_seconds' => 8,
        ]);

        Http::fake([
            'https://www.vlr.gg/matches' => Http::response($this->vlrMatchesListHtml()),
            'https://www.vlr.gg/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2' => Http::response($this->vlrMatchDetailHtml()),
        ]);

        $service = new VlrLiveScoreService(new LiveMatchMatcher);
        $matches = [[
            'game' => 'valorant',
            'team1' => 'T1',
            'team2' => 'Gen.G Esports',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => true,
            'series_score' => null,
            'score' => null,
        ]];

        $result = $service->enrich($matches);

        $this->assertTrue($result[0]['is_live']);
        $this->assertSame('1：0', $result[0]['series_score']);
        $this->assertSame('vlr', $result[0]['series_score_source']);
        $this->assertSame('12：11', $result[0]['score']);
        $this->assertSame('vlr', $result[0]['score_source']);
    }

    public function test_it_skips_non_valorant_matches(): void
    {
        Http::fake();

        $service = new VlrLiveScoreService(new LiveMatchMatcher);
        $matches = [[
            'game' => 'cs',
            'team1' => 'FaZe',
            'team2' => 'NAVI',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => true,
        ]];

        $result = $service->enrich($matches);

        $this->assertSame($matches, $result);
        Http::assertNothingSent();
    }

    public function test_it_handles_vlr_failure_gracefully(): void
    {
        config([
            'services.vlr.matches_url' => 'https://www.vlr.gg/matches',
        ]);

        Http::fake([
            'https://www.vlr.gg/matches' => Http::response('Service Unavailable', 503),
        ]);

        $service = new VlrLiveScoreService(new LiveMatchMatcher);
        $matches = [[
            'game' => 'valorant',
            'team1' => 'Gen.G',
            'team2' => 'T1',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => false,
        ]];

        $result = $service->enrich($matches);

        $this->assertFalse($result[0]['is_live']);
    }

    public function test_it_extracts_uncompleted_map_score_when_mod_active_class_is_missing(): void
    {
        $detailHtml = <<<'HTML'
<div class="match-header-vs">
    <div class="match-header-vs-score">
        <div class="sp-hide">
            <span>1</span>
            <span class="colon">:</span>
            <span>0</span>
        </div>
    </div>
</div>
<div class="vm-stats-game" data-game-id="101">
    <div class="map">Bind</div>
    <div class="score">13</div>
    <div class="score">8</div>
</div>
<div class="vm-stats-game" data-game-id="102">
    <div class="map">Haven</div>
    <div class="score">7</div>
    <div class="score">4</div>
</div>
<div class="vm-stats-game" data-game-id="103">
    <div class="map">Ascent</div>
    <div class="score">0</div>
    <div class="score">0</div>
</div>
HTML;

        Http::fake([
            'https://www.vlr.gg/matches' => Http::response($this->vlrMatchesListHtml()),
            'https://www.vlr.gg/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2' => Http::response($detailHtml),
        ]);

        $service = new VlrLiveScoreService(new LiveMatchMatcher);
        $matches = [[
            'game' => 'valorant',
            'team1' => 'Gen.G',
            'team2' => 'T1',
            'start_at' => CarbonImmutable::parse('2026-08-29T11:00:00Z'),
            'is_live' => true,
        ]];

        $result = $service->enrich($matches);

        $this->assertSame('1：0', $result[0]['series_score']);
        $this->assertSame('7：4', $result[0]['score']);
    }

    private function vlrMatchesListHtml(): string
    {
        return <<<'HTML'
<div class="wf-card">
    <a href="/742485/gen-g-vs-t1-vct-2026-pacific-stage-2-lr2" class="wf-module-item match-item mod-color mod-first">
        <div class="match-item-time">7:00 PM</div>
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
            <div class="ml mod-live">
                <div class="ml-status">LIVE</div>
            </div>
        </div>
        <div class="match-item-event text-of">
            VCT 2026: Pacific Stage 2
        </div>
    </a>
    <a href="/742486/fut-vs-liquid" class="wf-module-item match-item mod-color">
        <div class="match-item-time">11:00 PM</div>
        <div class="match-item-vs">
            <div class="match-item-vs-team">
                <div class="match-item-vs-team-name">FUT Esports</div>
                <div class="match-item-vs-team-score">–</div>
            </div>
            <div class="match-item-vs-team">
                <div class="match-item-vs-team-name">Team Liquid</div>
                <div class="match-item-vs-team-score">–</div>
            </div>
        </div>
        <div class="match-item-eta">
            Upcoming
        </div>
    </a>
</div>
HTML;
    }

    private function vlrMatchDetailHtml(): string
    {
        return <<<'HTML'
<div class="match-header-vs">
    <a class="match-header-link mod-1" href="/team/17/gen-g">
        <div class="match-header-link-name mod-1">
            <div class="wf-title-med mod-single">Gen.G</div>
        </div>
    </a>
    <div class="match-header-vs-score">
        <div class="match-header-vs-note">
            <span class="match-header-vs-note mod-live">live</span>
        </div>
        <div class="match-header-vs-score">
            <div class="sp-hide">
                <span>0</span>
                <span class="colon">:</span>
                <span>1</span>
            </div>
        </div>
    </div>
    <a class="match-header-link mod-2" href="/team/14/t1">
        <div class="match-header-link-name mod-2">
            <div class="wf-title-med mod-single">T1</div>
        </div>
    </a>
</div>
<div class="vm-stats-game" data-game-id="281167">
    <div class="map">Lotus</div>
    <div class="score">5</div>
    <div class="score">13</div>
</div>
<div class="vm-stats-game mod-active" data-game-id="281168">
    <div class="map">Split</div>
    <div class="score">11</div>
    <div class="score">12</div>
</div>
<div class="vm-stats-game" data-game-id="281169">
    <div class="map">Abyss</div>
    <div class="score">0</div>
    <div class="score">0</div>
</div>
HTML;
    }
}
