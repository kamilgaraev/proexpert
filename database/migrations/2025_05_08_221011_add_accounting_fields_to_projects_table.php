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
        Schema::table('projects', function (Blueprint $table) {
            // Поле для внешнего кода из СБИС/1С
            $table->string('external_code')->nullable()->comment('Внешний код из СБИС/1С');
            
            // Поле для привязки к статьям затрат
            $table->unsignedBigInteger('cost_category_id')->nullable()->comment('ID статьи затрат');
            
            // Поле для хранения дополнительных данных для интеграции
            $table->json('accounting_data')->nullable()->comment('Дополнительные данные для интеграции с бухгалтерскими системами');
            
            // Признак использования проекта в бухгалтерских выгрузках
            $table->boolean('use_in_accounting_reports')->default(true)->comment('Использовать в бухгалтерских отчетах');
            
            // Индекс для оптимизации поиска по внешнему коду
            $table->index('external_code');
            
            // Индекс для оптимизации поиска по категории затрат
            $table->index('cost_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['external_code']);
            $table->dropIndex(['cost_category_id']);
            $table->dropColumn('external_code');
            $table->dropColumn('cost_category_id');
            $table->dropColumn('accounting_data');
            $table->dropColumn('use_in_accounting_reports');
        });
    }
};
