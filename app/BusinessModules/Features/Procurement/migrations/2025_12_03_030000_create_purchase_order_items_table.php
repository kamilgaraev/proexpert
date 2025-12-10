<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создание таблицы позиций заказов поставщикам
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->onDelete('cascade')
                ->comment('Заказ поставщику');

            $table->foreignId('material_id')
                ->nullable()
                ->constrained('materials')
                ->onDelete('set null')
                ->comment('Материал из каталога');

            // Основные поля
            $table->string('material_name')->comment('Название материала');
            $table->decimal('quantity', 15, 3)->comment('Количество');
            $table->string('unit', 50)->comment('Единица измерения');
            $table->decimal('unit_price', 15, 2)->comment('Цена за единицу');
            $table->decimal('total_price', 15, 2)->comment('Общая стоимость');
            
            // Дополнительно
            $table->text('notes')->nullable()->comment('Примечания');
            $table->json('metadata')->nullable()->comment('Дополнительные данные');

            $table->timestamps();

            // Индексы
            $table->index('purchase_order_id');
            $table->index('material_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};

