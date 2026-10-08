<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Badge;

class BadgeTest extends ApiTestCase
{
    /** GET /api/badge 應回傳徽章列表 */
    public function test_get_badges_returns_list(): void
    {
        $response = $this->getJson('/api/badge');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success'])
            ->assertJsonStructure(['data' => [['id', 'name', 'color']]]);
    }

    /** 已登入時 POST /api/badge 應建立新徽章 */
    public function test_store_badge_creates_badge(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/badge', [
                'name' => '米其林推薦',
                'color' => 'warning',
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('badge', [
            'name' => '米其林推薦',
            'color' => 'warning',
        ]);
    }

    /** 未登入時 POST /api/badge 應被拒絕 */
    public function test_store_badge_requires_authentication(): void
    {
        $response = $this->postJson('/api/badge', [
            'name' => '測試徽章',
            'color' => 'danger',
        ]);

        $response->assertStatus(401);
    }

    /** 儲存文章時應能成功關聯多個徽章 */
    public function test_store_article_with_multiple_badges(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $badge1 = Badge::create(['name' => '已歇業', 'color' => 'danger', 'sort' => 1]);
        $badge2 = Badge::create(['name' => '二訪更新', 'color' => 'primary', 'sort' => 2]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'title' => '測試探店文章',
                'content' => '<p>內容</p>',
                'status' => 1,
                'selectedTags' => [],
                'selectedBadges' => [
                    ['id' => $badge1->id],
                    ['id' => $badge2->id],
                ],
            ]);

        $response->assertStatus(200);

        $article = Article::latest('id')->first();
        $this->assertCount(2, $article->badges);
        $this->assertEquals(['已歇業', '二訪更新'], $article->badges->pluck('name')->all());

        // 測試 GET /api/article 時有回傳 badges
        $listResponse = $this->getJson('/api/article');
        $listResponse->assertStatus(200);
        $articleData = collect($listResponse->json('data'))->firstWhere('id', $article->id);
        $this->assertNotEmpty($articleData['badges']);
        $this->assertEquals('已歇業', $articleData['badges'][0]['name']);
        $this->assertEquals('danger', $articleData['badges'][0]['color']);
    }

    /** 更新文章時應能同步更新徽章關聯 */
    public function test_update_article_syncs_badges(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $badge1 = Badge::create(['name' => '已歇業', 'color' => 'danger']);
        $badge2 = Badge::create(['name' => '已搬遷', 'color' => 'warning']);

        $article = Article::factory()->state(['status' => 1])->create();
        $article->badges()->attach($badge1->id);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'id' => $article->id,
                'title' => $article->title,
                'content' => $article->content,
                'status' => 1,
                'selectedTags' => [],
                'selectedBadges' => [
                    ['id' => $badge2->id],
                ],
            ]);

        $article->refresh();
        $this->assertFalse($article->badges->contains($badge1->id));
        $this->assertTrue($article->badges->contains($badge2->id));
    }

    /** 刪除文章時應自動解除徽章關聯 */
    public function test_destroy_article_detaches_badges(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $badge = Badge::create(['name' => '已歇業', 'color' => 'danger']);
        $article = Article::factory()->state(['status' => 1])->create();
        $article->badges()->attach($badge->id);

        $this->actingAs($admin, 'admin')
            ->deleteJson("/api/article/{$article->id}");

        $this->assertDatabaseMissing('article_badge', [
            'article_id' => $article->id,
        ]);
    }

    /** 前台首頁與文章詳細頁應正確渲染徽章 */
    public function test_article_listing_and_detail_render_badges(): void
    {
        $badge = Badge::create(['name' => '已歇業', 'color' => 'danger']);
        $article = Article::factory()->state(['status' => 1])->create(['title' => 'wpapa 鐵板早午餐']);
        $article->badges()->attach($badge->id);

        // 首頁列表 SSR 渲染
        $responseHome = $this->get('/');
        $responseHome->assertOk()
            ->assertSee('badge bg-danger', false)
            ->assertSee('已歇業')
            ->assertSee('wpapa 鐵板早午餐');

        // 文章詳細頁 SSR 渲染
        $responseArticle = $this->get("/article/{$article->id}");
        $responseArticle->assertOk()
            ->assertSee('badge bg-danger', false)
            ->assertSee('已歇業')
            ->assertSee('wpapa 鐵板早午餐')
            ->assertDontSee('店家狀態提醒：');
    }

    /** 測試切換徽章啟用/停用狀態 API */
    public function test_toggle_badge_status(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $badge = Badge::create(['name' => '測試啟用停用', 'color' => 'success', 'status' => 1]);

        $response = $this->actingAs($admin, 'admin')
            ->patchJson("/api/badge/{$badge->id}/status");

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'data' => ['status' => 0]]);

        $this->assertEquals(0, $badge->fresh()->status);
    }

    /** 自訂 Hex 色碼徽章應正確在 SSR 渲染 inline style */
    public function test_hex_color_badge_renders_inline_style(): void
    {
        $badge = Badge::create(['name' => '米其林一星', 'color' => '#6f42c1']);
        $article = Article::factory()->state(['status' => 1])->create(['title' => '高檔法式餐廳']);
        $article->badges()->attach($badge->id);

        $responseHome = $this->get('/');
        $responseHome->assertOk()
            ->assertSee('background-color: #6f42c1', false)
            ->assertSee('米其林一星');

        $responseArticle = $this->get("/article/{$article->id}");
        $responseArticle->assertOk()
            ->assertSee('background-color: #6f42c1', false)
            ->assertSee('米其林一星');
    }

    /** 提交重複徽章 ID 時應自動去重，資料庫中僅保留一筆關聯 */
    public function test_store_article_with_duplicate_badges_is_deduplicated(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $badge = Badge::create(['name' => '已歇業', 'color' => 'danger']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'title' => '去重測試文章',
                'content' => '<p>內容</p>',
                'status' => 1,
                'selectedTags' => [],
                'selectedBadges' => [
                    ['id' => $badge->id],
                    ['id' => $badge->id],
                ],
            ]);

        $response->assertStatus(200);

        $article = Article::latest('id')->first();
        $badgeCount = \Illuminate\Support\Facades\DB::table('article_badge')
            ->where('article_id', $article->id)
            ->where('badge_id', $badge->id)
            ->count();

        $this->assertEquals(1, $badgeCount);
        $this->assertCount(1, $article->badges);
    }

    /** article_badge 資料表應具有 (article_id, badge_id) 唯一約束，重複插入會拋出例外 */
    public function test_article_badge_table_enforces_unique_constraint(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('article_badge')->insert([
            'article_id' => 999,
            'badge_id' => 888,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('article_badge')->insert([
            'article_id' => 999,
            'badge_id' => 888,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
