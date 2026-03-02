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
            'title' => '我是Amanda',
            'sub_title' => '美食｜開箱｜生活',
            'description' => '土身土長的台中人，熱愛美食，也喜歡開箱',
            'picture' => '/images/about.jpg',
        ]);
    }
}
