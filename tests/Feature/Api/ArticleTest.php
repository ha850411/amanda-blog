<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Tag;

class ArticleTest extends ApiTestCase
{
    /** GET /api/article 應回傳分頁格式 */
    public function testGetArticlesReturnsPaginatedList(): void
    {
        Article::factory()->count(3)->create();

        $response = $this->getJson('/api/article');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success'])
            ->assertJsonStructure(['data', 'total', 'current_page', 'per_page', 'last_page']);
    }

    /** status 篩選參數應只回傳符合狀態的文章 */
    public function testGetArticlesFilterByStatus(): void
    {
        Article::factory()->create(['status' => 1]);
        Article::factory()->create(['status' => 0]);

        $response = $this->getJson('/api/article?status=1');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'total' => 1]);
    }

    /** tag 篩選參數應只回傳含有符合標籤的文章 */
    public function testGetArticlesFilterByTag(): void
    {
        $tag = Tag::factory()->create(['name' => 'laravel']);
        $article = Article::factory()->create();
        $article->tags()->attach($tag->id);

        Article::factory()->create(); // 無標籤文章

        $response = $this->getJson('/api/article?tag=laravel');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'total' => 1]);
    }

    /** show_first_image 參數應在每篇文章資料中附加 first_image 欄位 */
    public function testGetArticlesWithFirstImage(): void
    {
        Article::factory()->create([
            'content' => '<p><img src="https://example.com/photo.jpg" /></p>',
        ]);

        $response = $this->getJson('/api/article?show_first_image=1');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('first_image', $data[0]);
        $this->assertEquals('https://example.com/photo.jpg', $data[0]['first_image']);
    }

    /** 已登入時 POST /api/article（無 id）應新增文章 */
    public function testStoreArticleCreatesNewArticle(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag   = Tag::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'title'        => '新文章標題',
                'content'      => '文章內容',
                'status'       => 1,
                'selectedTags' => [['id' => $tag->id]],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('article', ['title' => '新文章標題']);
    }

    /** 已登入時 POST /api/article（帶 id）應更新既有文章 */
    public function testStoreArticleUpdatesExistingArticle(): void
    {
        $admin   = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $article = Article::factory()->create(['title' => '舊標題']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'id'           => $article->id,
                'title'        => '更新後標題',
                'content'      => '更新後內容',
                'status'       => 1,
                'selectedTags' => [],
            ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('article', [
            'id'    => $article->id,
            'title' => '更新後標題',
        ]);
    }

    /** 已登入時 DELETE /api/article/{id} 應刪除文章，回傳 204 */
    public function testDestroyArticle(): void
    {
        $admin   = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $article = Article::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->deleteJson("/api/article/{$article->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('article', ['id' => $article->id]);
    }

    /** 未登入時 POST /api/article 應回傳 401 */
    public function testStoreArticleRequiresAuthentication(): void
    {
        $response = $this->postJson('/api/article', [
            'title'   => '未授權文章',
            'content' => '內容',
        ]);

        $response->assertStatus(401);
    }

    /** 未登入時 DELETE /api/article/{id} 應回傳 401 */
    public function testDestroyArticleRequiresAuthentication(): void
    {
        $article = Article::factory()->create();

        $response = $this->deleteJson("/api/article/{$article->id}");

        $response->assertStatus(401);
    }
}
