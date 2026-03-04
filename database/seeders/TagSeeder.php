<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class TagSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Tag::create([
            'id' => 1,
            'name' => '台中美食',
            'parent_id' => 0,
            'sort' => 1,
        ]);

        Tag::create([
            'id' => 2,
            'name' => '牛排館',
            'parent_id' => 1,
            'sort' => 1,
        ]);

        Tag::create([
            'id' => 3,
            'name' => '火鍋店',
            'parent_id' => 1,
            'sort' => 2,
        ]);

        Tag::create([
            'id' => 4,
            'name' => '咖啡店',
            'parent_id' => 1,
            'sort' => 3,
        ]);

        Tag::create([
            'id' => 5,
            'name' => '早午餐',
            'parent_id' => 1,
            'sort' => 4,
        ]);

        Tag::create([
            'id' => 6,
            'name' => '宅配商品',
            'parent_id' => 0,
            'sort' => 2,
        ]);

        Tag::create([
            'id' => 7,
            'name' => '采耳體驗',
            'parent_id' => 0,
            'sort' => 3,
        ]);
    }
}
