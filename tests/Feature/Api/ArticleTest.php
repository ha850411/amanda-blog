<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Tag;
use Carbon\Carbon;

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

    /** perpage 參數應控制每頁筆數 */
    public function testGetArticlesRespectsPerpage(): void
    {
        Article::factory()->count(5)->create();

        $response = $this->getJson('/api/article?perpage=2');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'per_page' => 2, 'last_page' => 3]);

        $this->assertCount(2, $response->json('data'));
    }

    /** page 參數應控制當前頁碼 */
    public function testGetArticlesRespectsPage(): void
    {
        Article::factory()->count(5)->create();

        $response = $this->getJson('/api/article?perpage=2&page=2');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'current_page' => 2]);
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

    /** tag 篩選參數應只回傳含有符合標籤名稱的文章 */
    public function testGetArticlesFilterByTag(): void
    {
        $tag     = Tag::factory()->create(['name' => 'laravel']);
        $article = Article::factory()->create();
        $article->tags()->attach($tag->id);

        Article::factory()->create(); // 無標籤文章

        $response = $this->getJson('/api/article?tag=laravel');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'total' => 1]);
    }

    /** tagId 篩選參數應只回傳含有指定 tag id 的文章 */
    public function testGetArticlesFilterByTagId(): void
    {
        $tag     = Tag::factory()->create();
        $article = Article::factory()->create();
        $article->tags()->attach($tag->id);

        Article::factory()->create(); // 無標籤文章

        $response = $this->getJson("/api/article?tagId={$tag->id}");

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'total' => 1]);
    }

    /** start 篩選參數應只回傳建立時間在該日期之後的文章 */
    public function testGetArticlesFilterByStart(): void
    {
        Article::factory()->create(['created_at' => Carbon::parse('2024-01-01')]);
        Article::factory()->create(['created_at' => Carbon::parse('2024-06-01')]);

        $response = $this->getJson('/api/article?start=2024-03-01');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success', 'total' => 1]);
    }

    /** end 篩選參數應只回傳建立時間在該日期之前的文章 */
    public function testGetArticlesFilterByEnd(): void
    {
        Article::factory()->create(['created_at' => Carbon::parse('2024-01-01')]);
        Article::factory()->create(['created_at' => Carbon::parse('2024-06-01')]);

        $response = $this->getJson('/api/article?end=2024-03-01');

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

    /** 無圖片的文章，first_image 應為 null */
    public function testGetArticlesFirstImageIsNullWhenNoImage(): void
    {
        Article::factory()->create(['content' => '<p>純文字內容</p>']);

        $response = $this->getJson('/api/article?show_first_image=1');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('first_image', $data[0]);
        $this->assertNull($data[0]['first_image']);
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

    /** 新增文章時應正確建立文章與標籤的關聯 */
    public function testStoreArticleAttachesTags(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag   = Tag::factory()->create();

        $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'title'        => '有標籤文章',
                'content'      => '內容',
                'status'       => 1,
                'selectedTags' => [['id' => $tag->id]],
            ]);

        $article = Article::where('title', '有標籤文章')->first();
        $this->assertTrue($article->tags->contains($tag->id));
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

    /** 更新文章時應重設標籤關聯 */
    public function testStoreArticleReplacesTagsOnUpdate(): void
    {
        $admin      = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $oldTag     = Tag::factory()->create();
        $newTag     = Tag::factory()->create();
        $article    = Article::factory()->create();
        $article->tags()->attach($oldTag->id);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/article', [
                'id'           => $article->id,
                'title'        => $article->title,
                'content'      => $article->content,
                'status'       => 1,
                'selectedTags' => [['id' => $newTag->id]],
            ]);

        $article->refresh();
        $this->assertFalse($article->tags->contains($oldTag->id));
        $this->assertTrue($article->tags->contains($newTag->id));
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

    /** 刪除文章時應一併移除文章與標籤的關聯 */
    public function testDestroyArticleDetachesTags(): void
    {
        $admin   = Admin::create(['username' => 'admin', 'password' => 'secret']);
        $tag     = Tag::factory()->create();
        $article = Article::factory()->create();
        $article->tags()->attach($tag->id);

        $this->actingAs($admin, 'admin')
            ->deleteJson("/api/article/{$article->id}");

        $this->assertDatabaseMissing('article_tag', ['article_id' => $article->id]);
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
