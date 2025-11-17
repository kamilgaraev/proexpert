<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('counterparty_accounts', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('counterparty_organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            $table->foreignId('counterparty_contractor_id')->nullable()->constrained('contractors')->onDelete('cascade');
            
            // Балансы
            $table->decimal('receivable_balance', 15, 2)->default(0); // Нам должны
            $table->decimal('payable_balance', 15, 2)->default(0); // Мы должны
            $table->decimal('net_balance', 15, 2)->default(0); // Чистый баланс
            
            // Лимиты
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->integer('payment_terms_days')->default(30);
            
            // Статус
            $table->boolean('is_active')->default(true);
            $table->boolean('is_blocked')->default(false);
            $table->text('block_reason')->nullable();
            
            // Метрики
            $table->integer('total_invoices_count')->default(0);
            $table->integer('overdue_invoices_count')->default(0);
            $table->integer('avg_payment_delay_days')->default(0);
            
            $table->timestamp('last_transaction_at')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index(['organization_id', 'counterparty_organization_id']);
            $table->index(['organization_id', 'is_active']);
            $table->unique(['organization_id', 'counterparty_organization_id', 'counterparty_contractor_id'], 'unique_counterparty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counterparty_accounts');
    }
};

