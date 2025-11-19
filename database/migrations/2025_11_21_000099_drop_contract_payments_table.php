<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Удаление старой таблицы contract_payments после миграции данных
     */
    public function up(): void
    {
        // Проверяем что таблица существует
        if (!Schema::hasTable('contract_payments')) {
            \Log::info('payments.legacy_table_drop.skipped', [
                'reason' => 'Table contract_payments does not exist'
            ]);
            return;
        }
        
        // Проверяем что миграция данных была выполнена (есть новая таблица invoices)
        if (!Schema::hasTable('invoices')) {
            \Log::warning('payments.legacy_table_drop.aborted', [
                'reason' => 'Invoices table does not exist. Run data migration first.'
            ]);
            return;
        }
        
        // Удаляем старую таблицу contract_payments
        Schema::dropIfExists('contract_payments');
        
        \Log::info('payments.legacy_table_dropped', [
            'table' => 'contract_payments',
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстанавливаем таблицу contract_payments
        Schema::create('contract_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_type'); // advance, fact_payment, deferred_payment, other
            $table->string('reference_document_number')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('payment_date');
            $table->index('payment_type');
        });
        
        \Log::info('payments.legacy_table_restored', [
            'table' => 'contract_payments',
            'timestamp' => now()->toDateTimeString()
        ]);
    }
};

