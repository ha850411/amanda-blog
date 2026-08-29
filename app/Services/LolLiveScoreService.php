<?php

namespace App\Services;

class LolLiveScoreService
{
    public function __construct(
        private readonly OddsApiLiveScoreService $odds,
        private readonly RiotEsportsLiveScoreService $riotEsports,
        private readonly VlrLiveScoreService $vlr,
    ) {}

    /**
     * Directly requests live providers on every invocation. Odds-API.io
     * enriches LoL, CS2, and Valorant series scores, Riot enriches LoL game data,
     * and VLR.gg enriches Valorant live round and series scores.
     * No Laravel Cache or persistent application state is used.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $matches): array
    {
        $matches = $this->odds->enrich($matches);
        $matches = $this->riotEsports->enrich($matches);

        return $this->vlr->enrich($matches);
    }
}
