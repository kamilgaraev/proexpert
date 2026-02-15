<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('warehouse_balances', function (Blueprint $table) {
            // 1. Удаляем уникальный индекс "один товар на складе"
            $table->dropUnique('unique_warehouse_material');

            // 2. Переименовываем average_price в unit_price (цена конкретной партии)
            if (Schema::hasColumn('warehouse_balances', 'average_price')) {
                 $table->renameColumn('average_price', 'unit_price');
            }
            
            // Если колонки unit_price нет (и average_price не было), создаем
            if (!Schema::hasColumn('warehouse_balances', 'unit_price') && !Schema::hasColumn('warehouse_balances', 'average_price')) {
                $table->decimal('unit_price', 15, 2)->default(0);
            }

            // 3. Добавляем created_at для сортировки FIFO (если нет timestamps)
            // В модели WarehouseBalance было public $timestamps = false;
            if (!Schema::hasColumn('warehouse_balances', 'created_at')) {
                $table->timestamp('created_at')->nullable()->useCurrent();
            }
            
            // 4. Индекс для быстрого поиска партий (FIFO)
            // Ищем по складу, материалу и сортируем по дате создания
            $table->index(['warehouse_id', 'material_id', 'created_at'], 'idx_wb_fifo');
        });

        // Конвертируем существующие данные (если есть) в "первую партию"
        // (Это произойдет автоматически, так как мы просто переименовали колонку)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouse_balances', function (Blueprint $table) {
            // Возвращаем как было
            $table->dropIndex('idx_wb_fifo');
            
            if (Schema::hasColumn('warehouse_balances', 'created_at')) {
                $table->dropColumn('created_at');
            }

            $table->renameColumn('unit_price', 'average_price');
            
            // Восстанавливаем уникальность (ВНИМАНИЕ: это упадет, если уже есть дубликаты партий)
            // Поэтому в down() мы это делать рискованно, но для структуры надо
            // $table->unique(['warehouse_id', 'material_id'], 'unique_warehouse_material');
        });
    }
};
