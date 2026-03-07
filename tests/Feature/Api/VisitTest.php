<?php

namespace Tests\Feature\Api;

use App\Models\Visit;

class VisitTest extends ApiTestCase
{
    /** GET /api/visit 應回傳今日與累計瀏覽數 */
    public function testGetVisitCounts(): void
    {
        $today = date('Y/m/d');

        Visit::create(['ip' => '127.0.0.1', 'date' => $today]);
        Visit::create(['ip' => '127.0.0.2', 'date' => $today]);
        Visit::create(['ip' => '10.0.0.1', 'date' => '2025/01/01']); // 非今日

        $response = $this->getJson('/api/visit');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'today' => 2,
                    'total' => 3,
                ],
            ]);
    }

    /** POST /api/visit 應新增一筆訪客瀏覽紀錄並回傳 200 */
    public function testStoreVisitRecord(): void
    {
        $response = $this->postJson('/api/visit');

        $response->assertStatus(200)
            ->assertJson(['message' => '訪問紀錄新增成功'])
            ->assertJsonStructure(['data' => ['id', 'ip', 'date']]);

        $this->assertEquals(1, Visit::count());
    }
}
