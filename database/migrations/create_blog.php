<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('about')) {
            Schema::create('about', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title', 255)->nullable()->comment('標題');
                $table->string('sub_title', 255)->nullable()->comment('副標題');
                $table->text('description')->nullable()->comment('內容');
                $table->string('picture', 500)->nullable()->comment('大頭貼路徑');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tag')) {
            Schema::create('tag', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 300)->comment('標籤名稱');
                $table->unsignedInteger('parent_id')->default(0);
                $table->unsignedInteger('sort')->default(0)->comment('排序, 由小到大');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('article')) {
            Schema::create('article', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title', 255)->comment('文章標題');
                $table->text('content')->comment('文章內容');
                $table->unsignedTinyInteger('status')->default(1)->comment("0: 隱藏\n1: 公開\n2: 密碼");
                $table->string('password', 100)->nullable()->comment('文章密碼(非必填)');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('article_tag')) {
            Schema::create('article_tag', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('article_id')->nullable()->comment('文章id')->index('article_tag_article_id_IDX');
                $table->unsignedInteger('tag_id')->nullable()->comment('標籤id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('social')) {
            Schema::create('social', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('icon', 255)->comment('icon圖示class');
                $table->string('url', 500)->comment('連結');
                $table->tinyInteger('status')->default(1)->nullable()->comment('開啟狀態 0: 隱藏 1: 開啟');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('admins')) {
            Schema::create('admins', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 255)->unique();
                $table->string('password', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('visit')) {
            Schema::create('visit', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('ip', 100)->nullable()->comment('ip');
                $table->date('date')->index('visit_date_IDX')->comment('日期');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('social');
        Schema::dropIfExists('article_tag');
        Schema::dropIfExists('article');
        Schema::dropIfExists('tag');
        Schema::dropIfExists('about');
    }
};
