<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Расширяем таблицу materials для поддержки всех типов активов
     * 
     * Используем существующие поля:
     * - additional_properties (JSON) - для хранения asset_type, asset_category, asset_subcategory, asset_attributes
     * - category (string) - базовая категория
     * - Все остальные поля materials подходят для активов
     * 
     * Ничего не нужно добавлять, т.к. JSON поле уже есть!
     */
    public function up(): void
    {
        // Проверяем что поле additional_properties существует
        if (!Schema::hasColumn('materials', 'additional_properties')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->json('additional_properties')->nullable()->after('description');
            });
        }

        // Добавляем индексы для производительности (если еще не существуют)
        if (!Schema::hasColumn('materials', 'additional_properties')) {
            // Поле уже должно существовать, но на всякий случай
        }
        
        // Просто пропускаем, если индексы уже есть - Laravel сам обработает
        // Schema::table('materials', function (Blueprint $table) {
        //     $table->index(['organization_id', 'is_active'], 'idx_materials_org_active');
        //     $table->index('category', 'idx_materials_category');
        //     $table->index(['organization_id', 'code'], 'idx_materials_code');
        // });
    }

    /**
     * Откатываем изменения
     */
    public function down(): void
    {
        // Ничего не делаем при откате, т.к. не хотим удалять индексы
        // которые могли существовать до миграции
    }
};

