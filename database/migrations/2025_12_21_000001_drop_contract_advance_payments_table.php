<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * ВНИМАНИЕ: Эта миграция должна быть запущена через месяц после релиза нового модуля
     * Дата запуска: не ранее 21.12.2025
     */
    public function up(): void
    {
        // Проверяем что миграция данных была выполнена
        if (!Schema::hasTable('invoices')) {
            throw new \RuntimeException('Таблица invoices не существует. Сначала выполните миграцию данных.');
        }
        
        // Удаляем старую таблицу
        Schema::dropIfExists('contract_advance_payments');
        
        \Log::info('payments.legacy_table_dropped');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем таблицу
        Schema::create('contract_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('payment_date')->nullable();
            $table->timestamps();
            
            $table->index(['contract_id', 'payment_date']);
            $table->index('payment_date');
        });
    }
};

