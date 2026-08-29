<?php

namespace App\Services;

class LolLiveScoreService
{
    public function __construct(
        private readonly OddsApiLiveScoreService $odds,
        private readonly RiotEsportsLiveScoreService $riotEsports,
    ) {}

    /**
     * Directly requests live providers on every invocation. Odds-API.io
     * enriches LoL and CS2 series scores, while Riot enriches LoL game data.
     * No Laravel Cache or persistent application state is used.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        $matches = $this->odds->enrich($matches);

        return $this->riotEsports->enrich($matches);
    }
}
