<?php

namespace Tests\Unit;

use App\Services\StakeFixtureCodes;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StakeFixtureCodesTest extends TestCase
{
    private const ID = 'eac97d74-e71f-439e-99b9-eabe702f65b9';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array'], 'services.stake.odds_code_ttl_seconds' => 60]);
    }

    public function test_codes_are_stable_case_insensitive_and_expire_without_database_storage(): void
    {
        $service = new StakeFixtureCodes;
        $code = $service->forFixture(self::ID);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{5}$/', $code);
        $this->assertSame($code, (new StakeFixtureCodes)->forFixture(strtoupper(self::ID)));
        $this->assertSame(self::ID, $service->fixtureId(strtolower($code)));
        $this->travel(61)->seconds();
        $this->assertNull($service->fixtureId($code));
        $this->assertSame($code, $service->forFixture(self::ID));
    }

    public function test_relisting_a_fixture_refreshes_both_directions_of_the_ttl(): void
    {
        $service = new StakeFixtureCodes;
        $code = $service->forFixture(self::ID);
        $this->travel(40)->seconds();
        $this->assertSame($code, $service->forFixture(self::ID));
        $this->travel(40)->seconds();
        $this->assertSame(self::ID, $service->fixtureId($code));
        $this->travel(21)->seconds();
        $this->assertNull($service->fixtureId($code));
    }

    public function test_collision_preserves_existing_owner_and_uses_another_five_character_code(): void
    {
        $service = new StakeFixtureCodes;
        $first = $service->forFixture(self::ID);
        $otherId = '00000000-0000-0000-0000-000000000001';
        $cache = Cache::store('redis');
        $cache->forget('stake:odds:codes:v1:fixture:'.self::ID);
        $cache->put('stake:odds:codes:v1:code:'.$first, $otherId, 60);
        $replacement = $service->forFixture(self::ID);
        $this->assertNotSame($first, $replacement);
        $this->assertSame($otherId, $service->fixtureId($first));
        $this->assertSame(self::ID, $service->fixtureId($replacement));
        $this->assertSame($replacement, (new StakeFixtureCodes)->forFixture(self::ID));
    }
}
