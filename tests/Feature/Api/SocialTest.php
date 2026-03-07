<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Social;

class SocialTest extends ApiTestCase
{
    /** GET /api/social 應回傳所有社群連結 */
    public function testGetSocialsReturnsAllRecords(): void
    {
        Social::factory()->count(3)->create();

        $response = $this->getJson('/api/social');

        $response->assertStatus(200)
            ->assertJsonStructure(['data'])
            ->assertJsonCount(3, 'data');
    }

    /** 已登入時 PATCH /api/social/{id} 應更新 url 與 status */
    public function testUpdateSocial(): void
    {
        $admin  = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $social = Social::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->patchJson("/api/social/{$social->id}", [
                'url'    => 'https://github.com/test',
                'status' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => '社群 icon 更新成功']);

        $this->assertDatabaseHas('social', [
            'id'  => $social->id,
            'url' => 'https://github.com/test',
        ]);
    }

    /** 更新不存在的社群連結時應回傳 404 */
    public function testUpdateNonexistentSocialReturns404(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->patchJson('/api/social/999', [
                'url' => 'https://example.com',
            ]);

        $response->assertStatus(404);
    }

    /** 未登入時 PATCH /api/social/{id} 應回傳 401 */
    public function testUpdateSocialRequiresAuthentication(): void
    {
        $social = Social::factory()->create();

        $response = $this->patchJson("/api/social/{$social->id}", [
            'url' => 'https://example.com',
        ]);

        $response->assertStatus(401);
    }
}
