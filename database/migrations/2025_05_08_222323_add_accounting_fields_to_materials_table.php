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
        Schema::table('materials', function (Blueprint $table) {
            // Поле для внешнего кода из СБИС/1С
            $table->string('external_code')->nullable()->comment('Внешний код из СБИС/1С');
            
            // Поле для хранения кода номенклатуры в СБИС/1С
            $table->string('sbis_nomenclature_code')->nullable()->comment('Код номенклатуры в СБИС');
            
            // Поле для хранения кода единицы измерения в СБИС/1С
            $table->string('sbis_unit_code')->nullable()->comment('Код единицы измерения в СБИС');
            
            // Поле для хранения данных о номах списания
            $table->json('consumption_rates')->nullable()->comment('Нормы списания по видам работ');
            
            // Поле для хранения дополнительных данных для интеграции
            $table->json('accounting_data')->nullable()->comment('Дополнительные данные для интеграции с бухгалтерскими системами');
            
            // Признак использования материала в бухгалтерских выгрузках
            $table->boolean('use_in_accounting_reports')->default(true)->comment('Использовать в бухгалтерских отчетах');
            
            // Поле для счета учета в бухгалтерии
            $table->string('accounting_account')->nullable()->comment('Счет учета в бухгалтерии');
            
            // Индексы для оптимизации поиска
            $table->index('external_code');
            $table->index('sbis_nomenclature_code');
            $table->index('use_in_accounting_reports');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['external_code']);
            $table->dropIndex(['sbis_nomenclature_code']);
            $table->dropIndex(['use_in_accounting_reports']);
            
            $table->dropColumn('external_code');
            $table->dropColumn('sbis_nomenclature_code');
            $table->dropColumn('sbis_unit_code');
            $table->dropColumn('consumption_rates');
            $table->dropColumn('accounting_data');
            $table->dropColumn('use_in_accounting_reports');
            $table->dropColumn('accounting_account');
        });
    }
};
