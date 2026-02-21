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
        Schema::table('estimates', function (Blueprint $table) {
            $table->jsonb('statistics')->nullable()->comment('Предрассчитанная статистика (количество элементов, суммы)');
            $table->string('structure_cache_path')->nullable()->comment('Путь к закэшированному JSON-файлу полной структуры сметы');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['statistics', 'structure_cache_path']);
        });
    }
};
