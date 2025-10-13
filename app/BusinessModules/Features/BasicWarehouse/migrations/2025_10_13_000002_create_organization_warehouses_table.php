<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу складов организации
     */
    public function up(): void
    {
        Schema::create('organization_warehouses', function (Blueprint $table) {
            $table->id();
            
            // Связь с организацией
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            // Основная информация
            $table->string('name', 255);
            $table->string('code', 50);
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            
            // Тип склада
            $table->enum('warehouse_type', ['central', 'project', 'external'])->default('central');
            
            // Статусы
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            
            // Настройки (JSON)
            $table->json('settings')->nullable();
            
            // Контактная информация
            $table->string('contact_person', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('working_hours', 255)->nullable();
            
            // Условия хранения (JSON)
            $table->json('storage_conditions')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['organization_id', 'is_main'], 'idx_org_warehouses_main');
            $table->index(['organization_id', 'is_active'], 'idx_org_warehouses_active');
            $table->unique(['organization_id', 'code'], 'unq_org_warehouses_code');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_warehouses');
    }
};

