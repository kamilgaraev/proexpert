<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавление полей для иерархической структуры смет
     * 
     * - parent_work_id: связь с родительской работой ГЭСН
     * - is_not_accounted: флаг "не учтенного" материала (буква Н в колонке A)
     */
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            // Родительская работа (для связи механизмов/материалов/labor с основной работой ГЭСН)
            $table->foreignId('parent_work_id')
                ->nullable()
                ->after('estimate_section_id')
                ->constrained('estimate_items')
                ->onDelete('cascade')
                ->comment('ID родительской работы ГЭСН');
            
            // Флаг "не учтенный" материал/ресурс (буква Н в Excel)
            $table->boolean('is_not_accounted')
                ->default(false)
                ->after('is_active')
                ->comment('Не учтенный материал (обозначается буквой Н)');
            
            // Индекс для быстрого поиска подпозиций
            $table->index('parent_work_id');
        });
    }

    /**
     * Откат миграции
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropIndex(['parent_work_id']);
            $table->dropConstrainedForeignId('parent_work_id');
            $table->dropColumn('is_not_accounted');
        });
    }
};

