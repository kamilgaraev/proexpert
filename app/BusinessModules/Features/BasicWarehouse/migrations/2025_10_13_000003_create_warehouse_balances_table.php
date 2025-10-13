<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу остатков на складах
     */
    public function up(): void
    {
        Schema::create('warehouse_balances', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            // Количество
            $table->decimal('available_quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            
            // Цена
            $table->decimal('average_price', 15, 2)->default(0);
            
            // Уровни запаса
            $table->decimal('min_stock_level', 15, 3)->nullable();
            $table->decimal('max_stock_level', 15, 3)->nullable();
            
            // Адресное хранение (для AdvancedWarehouse)
            $table->string('location_code', 100)->nullable();
            
            // Партионный учет (для AdvancedWarehouse)
            $table->string('batch_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            
            // Дата последнего движения
            $table->timestamp('last_movement_at')->nullable();
            
            // Уникальность по складу + материалу
            $table->unique(['warehouse_id', 'material_id'], 'unique_warehouse_material');
            
            // Индексы для производительности
            $table->index(['organization_id', 'warehouse_id'], 'idx_wb_org_warehouse');
            $table->index(['organization_id', 'material_id'], 'idx_wb_org_material');
            $table->index(['available_quantity', 'min_stock_level'], 'idx_wb_low_stock');
            $table->index('last_movement_at', 'idx_wb_last_movement');
            $table->index('expiry_date', 'idx_wb_expiry');
            $table->index('batch_number', 'idx_wb_batch');
            $table->index('location_code', 'idx_wb_location');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_balances');
    }
};

