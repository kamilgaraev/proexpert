<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу правил автоматического пополнения
     */
    public function up(): void
    {
        Schema::create('auto_reorder_rules', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            // Правило
            $table->decimal('min_stock', 15, 3); // Минимальный уровень
            $table->decimal('max_stock', 15, 3); // Максимальный уровень
            $table->decimal('reorder_point', 15, 3); // Точка заказа
            $table->decimal('reorder_quantity', 15, 3); // Количество для заказа
            
            // Поставщик по умолчанию
            $table->foreignId('default_supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            
            // Статус
            $table->boolean('is_active')->default(true);
            
            // Даты последних проверок
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_ordered_at')->nullable();
            
            // Примечания
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['organization_id', 'is_active'], 'idx_reorder_org_active');
            $table->index(['warehouse_id', 'material_id'], 'idx_reorder_wh_mat');
            $table->unique(['warehouse_id', 'material_id'], 'unique_reorder_wh_material');
        });
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('auto_reorder_rules');
    }
};

