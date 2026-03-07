<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageTest extends ApiTestCase
{
    /** 已登入時上傳圖片至 S3（Storage::fake 模擬），應回傳 success = true 及 url */
    public function testUploadImageReturnsUrl(): void
    {
        Storage::fake('s3');

        $admin = Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->actingAs($admin, 'admin')
            ->post('/api/image/upload', [
                'upload' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['url']);
    }

    /** 未登入時 POST /api/image/upload 應回傳 401 */
    public function testUploadRequiresAuthentication(): void
    {
        $response = $this->post('/api/image/upload', [
            'upload' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(401);
    }
}
