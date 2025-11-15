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
        Schema::table('modules', function (Blueprint $table) {
            $table->string('development_status', 50)
                ->default('stable')
                ->after('is_system_module')
                ->comment('Статус разработки модуля: stable, beta, alpha, development, coming_soon, deprecated');
            
            // Индекс для фильтрации по статусу
            $table->index('development_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropIndex(['development_status']);
            $table->dropColumn('development_status');
        });
    }
};
