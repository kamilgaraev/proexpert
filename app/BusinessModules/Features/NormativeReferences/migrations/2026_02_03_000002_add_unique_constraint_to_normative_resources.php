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
        Schema::table('normative_resources', function (Blueprint $table) {
            // Добавляем уникальный композитный индекс для поддержки UPSERT
            $table->unique(['code', 'source'], 'normative_resources_code_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('normative_resources', function (Blueprint $table) {
            $table->dropUnique('normative_resources_code_source_unique');
        });
    }
};
