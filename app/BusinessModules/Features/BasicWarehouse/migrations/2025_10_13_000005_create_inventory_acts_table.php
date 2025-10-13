<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу актов инвентаризации
     */
    public function up(): void
    {
        Schema::create('inventory_acts', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            
            // Информация об акте
            $table->string('act_number', 100)->unique();
            $table->enum('status', ['draft', 'in_progress', 'completed', 'approved', 'cancelled'])->default('draft');
            
            // Даты
            $table->date('inventory_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Участники
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->json('commission_members')->nullable(); // Массив ID участников комиссии
            
            // Результаты
            $table->text('notes')->nullable();
            $table->json('summary')->nullable(); // Сводка по расхождениям
            
            $table->timestamps();
            
            // Индексы
            $table->index(['organization_id', 'warehouse_id'], 'idx_inventory_org_wh');
            $table->index(['status', 'inventory_date'], 'idx_inventory_status_date');
            $table->index('act_number', 'idx_inventory_act_number');
        });
        
        // Таблица позиций акта инвентаризации
        Schema::create('inventory_act_items', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('inventory_act_id')->constrained('inventory_acts')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            // Количество
            $table->decimal('expected_quantity', 15, 3); // По данным системы
            $table->decimal('actual_quantity', 15, 3)->nullable(); // Фактическое
            $table->decimal('difference', 15, 3)->nullable(); // Расхождение
            
            // Цена
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_value', 15, 2)->nullable();
            
            // Адрес хранения
            $table->string('location_code', 100)->nullable();
            $table->string('batch_number', 100)->nullable();
            
            // Примечания
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index('inventory_act_id', 'idx_items_act');
            $table->index('material_id', 'idx_items_material');
            $table->unique(['inventory_act_id', 'material_id', 'batch_number'], 'unique_act_material_batch');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_act_items');
        Schema::dropIfExists('inventory_acts');
    }
};

