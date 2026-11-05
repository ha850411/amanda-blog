<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class StakeFixtureCodes
{
    // 31 symbols: no 0/O or 1/I/L. Codes identify fixtures, not credentials.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function forFixture(string $fixtureId): string
    {
        $fixtureId = strtolower($fixtureId);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $fixtureId)) {
            throw new InvalidArgumentException('Invalid Stake fixture ID.');
        }
        $cache = Cache::store('redis');
        $ttl = max(60, (int) config('services.stake.odds_code_ttl_seconds', 86400));

        // Serialize collision checks and TTL refresh across queue workers.
        // Only fixture identity is stored here; never market data.
        return $cache->lock('stake:odds:codes:v1:lock', 10)->block(3, function () use ($cache, $fixtureId, $ttl): string {
            $fixtureKey = 'stake:odds:codes:v1:fixture:'.$fixtureId;
            $existing = $cache->get($fixtureKey);
            if (is_string($existing) && $cache->get($this->codeKey($existing)) === $fixtureId) {
                $cache->put($this->codeKey($existing), $fixtureId, $ttl);
                $cache->put($fixtureKey, $existing, $ttl);

                return $existing;
            }
            for ($attempt = 0; $attempt < 64; $attempt++) {
                $value = (int) hexdec(substr(hash('sha256', $fixtureId.':'.$attempt), 0, 12));
                $code = '';
                for ($i = 0; $i < 5; $i++) {
                    $code = self::ALPHABET[$value % 31].$code;
                    $value = intdiv($value, 31);
                }
                $owner = $cache->get($this->codeKey($code));
                if ($owner !== null && $owner !== $fixtureId) {
                    continue;
                }
                $cache->put($this->codeKey($code), $fixtureId, $ttl);
                $cache->put($fixtureKey, $code, $ttl);

                return $code;
            }

            throw new RuntimeException('Unable to allocate a unique Stake fixture code.');
        });
    }

    public function fixtureId(string $code): ?string
    {
        $id = Cache::store('redis')->get($this->codeKey(strtoupper($code)));

        return is_string($id) ? $id : null;
    }

    private function codeKey(string $code): string
    {
        return 'stake:odds:codes:v1:code:'.$code;
    }
}
