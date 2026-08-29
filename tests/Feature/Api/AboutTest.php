<?php

namespace Tests\Feature\Api;

use App\Models\About;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AboutTest extends ApiTestCase
{
    /** 有資料時 GET /api/about 應回傳關於我內容 */
    public function test_get_about_returns_data(): void
    {
        About::factory()->create(['title' => '關於Amanda']);

        $response = $this->getJson('/api/about');

        $response->assertStatus(200)
            ->assertJsonPath('data.title', '關於Amanda');
    }

    /** 無資料時 GET /api/about 的 data 應為 null */
    public function test_get_about_returns_null_when_no_record(): void
    {
        $response = $this->getJson('/api/about');

        $response->assertStatus(200)
            ->assertJson(['data' => null]);
    }

    /** 已登入時可更新文字欄位，應回傳 success = true 並寫入資料庫 */
    public function test_update_about_text_fields(): void
    {
        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/api/about', [
                'title' => '測試標題',
                'sub_title' => '測試副標題',
                'description' => '測試內容',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('about', ['title' => '測試標題']);
    }

    /** 未登入時 POST /api/about 應回傳 401 */
    public function test_update_about_requires_authentication(): void
    {
        $response = $this->postJson('/api/about', [
            'title' => '測試標題',
        ]);

        $response->assertStatus(401);
    }

    /** 已登入時可上傳頭像並更新，S3 操作由 Storage::fake('s3') 模擬 */
    public function test_update_about_with_avatar(): void
    {
        Storage::fake('s3');

        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);
        About::factory()->create(['picture' => null]);

        $response = $this->actingAs($admin, 'admin')
            ->post('/api/about', [
                'title' => '有頭像的標題',
                'avatar' => UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg'),
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
