<?php

namespace App\Services;

class LolLiveScoreService
{
    public function __construct(
        private readonly OddsApiLiveScoreService $odds,
        private readonly RiotEsportsLiveScoreService $riotEsports,
    ) {}

    /**
     * Directly requests both live providers on every invocation. No Laravel
     * Cache or persistent application state is used for live scores.
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
