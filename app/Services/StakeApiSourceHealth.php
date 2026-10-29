<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class StakeApiSourceHealth
{
    public function isCoolingDown(array $source, int $seconds): bool
    {
        if ($seconds === 0) {
            return false;
        }

        try {
            return Cache::get($this->key($source)) === true;
        } catch (Throwable) {
            // A cache outage must not prevent a live API query.
            return false;
        }
    }

    public function markFailure(array $source, int $seconds): void
    {
        if ($seconds === 0) {
            return;
        }

        try {
            Cache::put($this->key($source), true, $seconds);
        } catch (Throwable) {
            // Continue normal source failover if the cache is unavailable.
        }
    }

    private function key(array $source): string
    {
        // Separate direct/proxy routes and invalidate health after credentials change.
        // Only a hash and a boolean are stored; no account data or credentials.
        $identity = [$source['url'], $source['proxy'], config('services.stake.access_token')];

        return 'stake:source:cooldown:v1:'.hash('sha256', serialize($identity));
    }
}
