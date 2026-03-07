<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Tag;

class TagTest extends ApiTestCase
{
    /** GET /api/tag 應回傳含 children 的樹狀標籤結構 */
    public function testGetTagTreeReturnsParentTagsWithChildren(): void
    {
        $parent = Tag::factory()->create(['parent_id' => 0, 'sort' => 1]);
        Tag::create(['name' => '子標籤', 'parent_id' => $parent->id, 'sort' => 1]);

        $response = $this->getJson('/api/tag');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'children']]]);
    }

    /** 已登入時 POST /api/tag 應同時建立主標籤與子標籤 */
    public function testStoreTagCreatesParentWithChildren(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/tag', [
                'name'     => '主標籤',
                'children' => ['子標籤一', '子標籤二'],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tag', ['name' => '主標籤', 'parent_id' => 0]);
        $this->assertDatabaseHas('tag', ['name' => '子標籤一']);
        $this->assertDatabaseHas('tag', ['name' => '子標籤二']);
    }

    /** POST /api/tag 缺少 name 時應回傳 422 驗證錯誤 */
    public function testStoreTagRequiresName(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/tag', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** 已登入時 PUT /api/tag/{id} 應更新主標籤名稱 */
    public function testUpdateTag(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag   = Tag::factory()->create(['name' => '舊名稱', 'parent_id' => 0]);

        $response = $this->actingAs($admin, 'admin')
            ->putJson("/api/tag/{$tag->id}", [
                'name'     => '新名稱',
                'children' => [],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tag', ['id' => $tag->id, 'name' => '新名稱']);
    }

    /** 嘗試更新子標籤時應回傳 422，因為僅允許編輯主選單 */
    public function testUpdateChildTagReturnsError(): void
    {
        $admin  = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $parent = Tag::factory()->create(['parent_id' => 0]);
        $child  = Tag::create(['name' => '子標籤', 'parent_id' => $parent->id, 'sort' => 1]);

        $response = $this->actingAs($admin, 'admin')
            ->putJson("/api/tag/{$child->id}", [
                'name'     => '嘗試修改子標籤',
                'children' => [],
            ]);

        $response->assertStatus(422);
    }

    /** 已登入時 DELETE /api/tag/{id} 應同時刪除主標籤及其子標籤 */
    public function testDestroyTagAlsoDeletesChildren(): void
    {
        $admin  = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $parent = Tag::factory()->create(['parent_id' => 0]);
        $child  = Tag::create(['name' => '子標籤', 'parent_id' => $parent->id, 'sort' => 1]);

        $response = $this->actingAs($admin, 'admin')
            ->deleteJson("/api/tag/{$parent->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('tag', ['id' => $parent->id]);
        $this->assertDatabaseMissing('tag', ['id' => $child->id]);
    }

    /** 已登入時 POST /api/tag/sort 可將指定標籤往上移動一位 */
    public function testUpdateSortMovesTagUp(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag1  = Tag::factory()->create(['parent_id' => 0, 'sort' => 1]);
        $tag2  = Tag::factory()->create(['parent_id' => 0, 'sort' => 2]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/tag/sort', [
                'id'        => $tag2->id,
                'direction' => 'up',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEquals(1, Tag::find($tag2->id)->sort);
        $this->assertEquals(2, Tag::find($tag1->id)->sort);
    }

    /** 已在最前面的標籤執行往上排序時，應回傳 success = false */
    public function testUpdateSortFailsWhenAlreadyFirst(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag   = Tag::factory()->create(['parent_id' => 0, 'sort' => 1]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/tag/sort', [
                'id'        => $tag->id,
                'direction' => 'up',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => false]);
    }

    /** 未登入時 POST /api/tag 應回傳 401 */
    public function testStoreTagRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/tag', ['name' => '未授權標籤']);

        $response->assertStatus(401);
    }
}
