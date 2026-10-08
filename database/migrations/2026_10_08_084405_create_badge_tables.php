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
        if (! Schema::hasTable('badge')) {
            Schema::create('badge', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 100)->comment('徽章名稱，如已歇業、搬遷');
                $table->string('color', 50)->default('danger')->comment('徽章顏色樣式，如danger, warning, primary');
                $table->unsignedTinyInteger('status')->default(1)->comment('0: 停用, 1: 啟用');
                $table->unsignedInteger('sort')->default(0)->comment('排序，由小到大');
                $table->timestamps();
            });

            // 預先塞入常用徽章預設值
            DB::table('badge')->insert([
                [
                    'name' => '已歇業',
                    'color' => 'danger',
                    'status' => 1,
                    'sort' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => '已搬遷',
                    'color' => 'warning',
                    'status' => 1,
                    'sort' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => '暫停營業',
                    'color' => 'secondary',
                    'status' => 1,
                    'sort' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => '二訪更新',
                    'color' => 'primary',
                    'status' => 1,
                    'sort' => 4,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        if (! Schema::hasTable('article_badge')) {
            Schema::create('article_badge', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('article_id')->comment('文章id')->index('article_badge_article_id_idx');
                $table->unsignedInteger('badge_id')->comment('徽章id')->index('article_badge_badge_id_idx');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_badge');
        Schema::dropIfExists('badge');
    }
};
