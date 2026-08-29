<?php

namespace Tests\Feature\Api;

use App\Models\Admin;

class LoginTest extends ApiTestCase
{
    /** 使用正確帳密登入，應回傳 200 且 success = true */
    public function test_login_with_valid_credentials(): void
    {
        Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /** 密碼錯誤時，應回傳 401 且 success = false */
    public function test_login_with_invalid_password(): void
    {
        Admin::create(['username' => 'admin', 'password' => 'secret']);

        $response = $this->postJson('/api/admin/login', [
            'username' => 'admin',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /** 帳號不存在時，應回傳 401 */
    public function test_login_with_nonexistent_username(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'username' => 'nobody',
            'password' => 'secret',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /** 未帶 username / password 欄位，應回傳 422 驗證錯誤 */
    public function test_login_validation_fails_when_fields_missing(): void
    {
        $response = $this->postJson('/api/admin/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }
}
