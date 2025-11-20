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
            
            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->onDelete('set null');
            
            // Тип и номер документа
            $table->string('document_type'); // payment_order, payment_request, invoice, etc.
            $table->string('document_number')->unique();
            $table->date('document_date');
            
            // Плательщик
            $table->foreignId('payer_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            
            $table->foreignId('payer_contractor_id')
                ->nullable()
                ->constrained('contractors')
                ->onDelete('set null');
            
            // Получатель
            $table->foreignId('payee_organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            
            $table->foreignId('payee_contractor_id')
                ->nullable()
                ->constrained('contractors')
                ->onDelete('set null');
            
            // Суммы
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('RUB');
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('amount_without_vat', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->nullable();
            
            // Статусы
            $table->string('status')->default('draft'); // draft, submitted, pending_approval, approved, rejected, scheduled, partially_paid, paid, cancelled
            $table->string('workflow_stage')->nullable(); // creation, approval, payment, completed
            
            // Источник документа (Invoice, Contract, etc.)
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            
            // Сроки
            $table->date('due_date')->nullable();
            $table->integer('payment_terms_days')->nullable();
            
            // Описание и назначение платежа
            $table->text('description')->nullable();
            $table->text('payment_purpose')->nullable();
            
            // Прикрепленные документы
            $table->json('attached_documents')->nullable();
            
            // Банковские реквизиты
            $table->string('bank_account')->nullable();
            $table->string('bank_bik')->nullable();
            $table->string('bank_correspondent_account')->nullable();
            $table->string('bank_name')->nullable();
            
            // Метаданные
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            
            // Пользователи
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            // Временные метки
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
            $table->index('due_date');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'document_type']);
            $table->index(['source_type', 'source_id']);
            $table->index('created_at');
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

