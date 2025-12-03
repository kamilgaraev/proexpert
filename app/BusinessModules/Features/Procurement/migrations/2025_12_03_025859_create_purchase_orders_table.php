<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создание таблицы заказов поставщикам
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('purchase_request_id')
                ->nullable()
                ->constrained('purchase_requests')
                ->onDelete('set null')
                ->comment('Связь с заявкой на закупку');

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->onDelete('restrict')
                ->comment('Поставщик');

            $table->foreignId('contract_id')
                ->nullable()
                ->constrained('contracts')
                ->onDelete('set null')
                ->comment('Связь с договором поставки');

            // Основные поля
            $table->string('order_number')->unique()->comment('Номер заказа');
            $table->date('order_date')->comment('Дата заказа');
            $table->string('status', 50)->default('draft')->comment('Статус заказа');
            
            // Суммы
            $table->decimal('total_amount', 15, 2)->default(0)->comment('Общая сумма заказа');
            $table->string('currency', 3)->default('RUB')->comment('Валюта');

            // Даты
            $table->date('delivery_date')->nullable()->comment('Планируемая дата доставки');
            $table->date('sent_at')->nullable()->comment('Дата отправки поставщику');
            $table->date('confirmed_at')->nullable()->comment('Дата подтверждения поставщиком');

            // Дополнительно
            $table->text('notes')->nullable()->comment('Примечания');
            $table->json('metadata')->nullable()->comment('Дополнительные данные');

            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'supplier_id']);
            $table->index('purchase_request_id');
            $table->index('order_number');
            $table->index('order_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
