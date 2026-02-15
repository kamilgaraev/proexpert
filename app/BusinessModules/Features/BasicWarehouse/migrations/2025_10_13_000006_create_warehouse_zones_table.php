<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицу зон хранения
     */
    public function up(): void
    {
        if (!Schema::hasTable('warehouse_zones')) {
            Schema::create('warehouse_zones', function (Blueprint $table) {
                $table->id();
                
                // Связи
                $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
                
                // Информация о зоне
                $table->string('name', 255);
                $table->string('code', 50);
                $table->enum('zone_type', ['storage', 'receiving', 'shipping', 'quarantine', 'returns'])->default('storage');
                
                // Адресная система (стеллаж-полка-ячейка)
                $table->string('rack_number', 50)->nullable();
                $table->string('shelf_number', 50)->nullable();
                $table->string('cell_number', 50)->nullable();
                
                // Характеристики
                $table->decimal('capacity', 15, 2)->nullable(); // м³ или другая единица
                $table->decimal('max_weight', 15, 2)->nullable(); // кг
                $table->json('storage_conditions')->nullable(); // температура, влажность и т.д.
                
                // Статус
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                
                $table->timestamps();
                
                // Индексы
                $table->index(['warehouse_id', 'is_active'], 'idx_zones_wh_active');
                $table->index('zone_type', 'idx_zones_type');
                $table->unique(['warehouse_id', 'code'], 'unique_warehouse_zone_code');
            });
        }
    }

    /**
     * Откатываем миграцию
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_zones');
    }
};
