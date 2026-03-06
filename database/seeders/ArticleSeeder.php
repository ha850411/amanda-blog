<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\Article;
use Illuminate\Database\Seeder;

class ArticleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 使用隨機 Tag 的 id 來關聯 Article
        $tagIds = Tag::pluck('id')->toArray();
        for ($i = 1; $i <= 20; $i++) {
            // 隨機取 1~3 個 Tag id
            $filterTags = array_rand(array_flip($tagIds), rand(1, 3));
            Article::factory()
                    ->hasAttached($filterTags, [], 'tags')->create([
                    'title' => "文章標題 $i",
                    'content' => "這是文章內容 $i",
                    'status' => 1
                ]);
        }
    }
}
