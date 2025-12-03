<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создание таблицы коммерческих предложений от поставщиков
     */
    public function up(): void
    {
        Schema::create('supplier_proposals', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->onDelete('cascade')
                ->comment('Связь с заказом');

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->onDelete('restrict')
                ->comment('Поставщик');

            // Основные поля
            $table->string('proposal_number')->unique()->comment('Номер КП');
            $table->date('proposal_date')->comment('Дата КП');
            $table->string('status', 50)->default('draft')->comment('Статус КП');

            // Суммы
            $table->decimal('total_amount', 15, 2)->default(0)->comment('Общая сумма КП');
            $table->string('currency', 3)->default('RUB')->comment('Валюта');

            // Срок действия
            $table->date('valid_until')->nullable()->comment('Срок действия КП');

            // Позиции КП (JSON)
            $table->json('items')->nullable()->comment('Позиции коммерческого предложения');

            // Дополнительно
            $table->text('notes')->nullable()->comment('Примечания');
            $table->json('metadata')->nullable()->comment('Дополнительные данные');

            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index('purchase_order_id');
            $table->index('proposal_number');
            $table->index('proposal_date');
            $table->index('valid_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_proposals');
    }
};
