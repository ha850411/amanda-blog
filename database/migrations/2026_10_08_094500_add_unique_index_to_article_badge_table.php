<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('article_badge')) {
            // 1. 先清理既有的重複資料，僅保留 id 最小的一筆
            $duplicates = DB::table('article_badge')
                ->select('article_id', 'badge_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
                ->groupBy('article_id', 'badge_id')
                ->having('total', '>', 1)
                ->get();

            foreach ($duplicates as $dup) {
                DB::table('article_badge')
                    ->where('article_id', $dup->article_id)
                    ->where('badge_id', $dup->badge_id)
                    ->where('id', '!=', $dup->keep_id)
                    ->delete();
            }

            // 2. 建立 (article_id, badge_id) 複合唯一索引
            Schema::table('article_badge', function (Blueprint $table): void {
                $table->unique(['article_id', 'badge_id'], 'article_badge_article_badge_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('article_badge')) {
            Schema::table('article_badge', function (Blueprint $table): void {
                $table->dropUnique('article_badge_article_badge_unique');
            });
        }
    }
};
