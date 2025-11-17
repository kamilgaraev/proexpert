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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            
            // Связь со счётом
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            
            // Плательщик / Получатель
            $table->foreignId('payer_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('payee_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('payer_contractor_id')->nullable()->constrained('contractors')->onDelete('set null');
            $table->foreignId('payee_contractor_id')->nullable()->constrained('contractors')->onDelete('set null');
            
            // Сумма
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('RUB');
            
            // Способ оплаты
            $table->string('payment_method');
            
            // Референс
            $table->string('reference_number')->nullable();
            $table->string('bank_transaction_id')->nullable();
            
            // Даты
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            
            // Статус
            $table->string('status')->default('pending');
            
            // Gateway (для онлайн платежей)
            $table->string('payment_gateway_id')->nullable();
            $table->json('gateway_response')->nullable();
            
            // Документы
            $table->string('proof_document_url')->nullable();
            
            // Дополнительно
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            // Пользователи
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            
            // Индексы
            $table->index(['invoice_id', 'status']);
            $table->index(['organization_id', 'transaction_date']);
            $table->index(['project_id', 'transaction_date']);
            $table->index('reference_number');
            $table->index('bank_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};

