<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            // Убираем template_id - упрощаем, каждый лендинг уникален
            $table->dropColumn('template_id');
            
            // Изменяем enum status - убираем maintenance, только draft/published
            $table->dropColumn('status');
        });
        
        Schema::table('holding_sites', function (Blueprint $table) {
            // Добавляем новый enum без maintenance
            $table->enum('status', ['draft', 'published'])->default('draft')->after('analytics_config');
            
            // Добавляем уникальный индекс - один лендинг на холдинг
            $table->unique(['organization_group_id'], 'one_landing_per_holding');
        });
        
        // Обновляем комментарии для ясности
        DB::statement("COMMENT ON TABLE holding_sites IS 'Лендинги холдингов - один лендинг на холдинг (упрощенная Тильда)'");
        DB::statement("COMMENT ON COLUMN holding_sites.title IS 'Заголовок лендинга'");
        DB::statement("COMMENT ON COLUMN holding_sites.theme_config IS 'Настройки темы (цвета, шрифты) как в Тильде'");
    }

    public function down(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            // Убираем уникальный constraint
            $table->dropUnique('one_landing_per_holding');
            
            // Убираем новый enum
            $table->dropColumn('status');
        });
        
        Schema::table('holding_sites', function (Blueprint $table) {
            // Возвращаем старый enum с maintenance
            $table->enum('status', ['draft', 'published', 'maintenance'])->default('draft')->after('analytics_config');
            
            // Возвращаем template_id
            $table->string('template_id')->default('default')->after('favicon_url');
        });
    }
};
