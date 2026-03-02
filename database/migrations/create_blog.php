<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__ . '/../schema/blog.sql'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
