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
        Schema::create('payment_documents', function (Blueprint $table) {
            $table->id();
            
            // Организация-владелец документа
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            
            // Тип и номер документа
            $table->string('document_type', 50); // payment_request, invoice, payment_order, etc.
            $table->string('document_number', 100)->unique();
            $table->date('document_date');
            
            // Стороны сделки (plательщик)
            $table->foreignId('payer_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('payer_contractor_id')->nullable()->constrained('contractors')->onDelete('set null');
            
            // Стороны сделки (получатель)
            $table->foreignId('payee_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('payee_contractor_id')->nullable()->constrained('contractors')->onDelete('set null');
            
            // Финансы
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('RUB');
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('amount_without_vat', 15, 2);
            
            // Оплачено (для частичных оплат)
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2);
            
            // Статус и workflow
            $table->string('status', 50)->default('draft'); // draft, submitted, approved, paid, etc.
            $table->string('workflow_stage', 50)->nullable(); // current approval stage
            
            // Привязка к источнику (polymorphic)
            $table->string('source_type')->nullable(); // Contract, Act, Estimate, Project
            $table->unsignedBigInteger('source_id')->nullable();
            
            // Сроки
            $table->date('due_date')->nullable();
            $table->integer('payment_terms_days')->nullable();
            
            // Детали
            $table->text('description')->nullable();
            $table->text('payment_purpose')->nullable(); // назначение платежа для банка
            $table->json('attached_documents')->nullable(); // массив файлов
            
            // Банковские реквизиты (для ускорения, дублируются)
            $table->string('bank_account', 20)->nullable();
            $table->string('bank_bik', 9)->nullable();
            $table->string('bank_correspondent_account', 20)->nullable();
            $table->string('bank_name')->nullable();
            
            // Метаданные
            $table->json('metadata')->nullable(); // доп.данные
            $table->text('notes')->nullable();
            
            // Пользователи
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Даты
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index('organization_id');
            $table->index('project_id');
            $table->index('document_type');
            $table->index('status');
            $table->index('document_date');
            $table->index('due_date');
            $table->index(['payer_organization_id', 'status']);
            $table->index(['payee_organization_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index(['organization_id', 'status', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_documents');
    }
};

