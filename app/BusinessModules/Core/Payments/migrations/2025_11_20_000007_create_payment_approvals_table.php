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
        Schema::create('payment_approvals', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('payment_document_id')
                ->constrained('payment_documents')
                ->onDelete('cascade');
            
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            
            // Роль утверждающего
            $table->string('approval_role'); // financial_director, chief_accountant, general_director, etc.
            
            // Пользователь-утверждающий
            $table->foreignId('approver_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            // Уровень и порядок утверждения
            $table->integer('approval_level')->default(1); // 1, 2, 3...
            $table->integer('approval_order')->default(1); // Порядок внутри уровня
            
            // Статус утверждения
            $table->string('status')->default('pending'); // pending, approved, rejected, skipped
            
            // Условия утверждения
            $table->decimal('amount_threshold', 15, 2)->nullable(); // Лимит суммы для данного утверждающего
            $table->json('conditions')->nullable(); // Дополнительные условия
            
            // Решение
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            
            // Уведомления
            $table->timestamp('notified_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            
            // Дополнительно
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index('payment_document_id');
            $table->index('organization_id');
            $table->index('approver_user_id');
            $table->index('approval_role');
            $table->index('status');
            $table->index('approval_level');
            $table->index(['payment_document_id', 'status']);
            $table->index(['approver_user_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_approvals');
    }
};

