<?php

namespace Database\Seeders;

use App\Models\About;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class AboutSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        About::create([
            'title' => 'Amanda | 探店 | 美食 | 生活 | 開箱',
            'sub_title' => "🏠探店🍜美食🕊️生活📦開箱 台中生活圈跑跳🌇\n💌合作邀約歡迎 summer.hung222@gmail.com",
            'description' => '土身土長的台中人，熱愛美食，也喜歡開箱',
            'picture' => '/images/about.jpg',
        ]);
    }
}
