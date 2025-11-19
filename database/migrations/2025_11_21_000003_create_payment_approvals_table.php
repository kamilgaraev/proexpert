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
            
            $table->foreignId('payment_document_id')->constrained('payment_documents')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            // Роль и пользователь утверждающего
            $table->string('approval_role', 100); // financial_director, chief_accountant, project_manager
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Порядок утверждения (для multi-level approval)
            $table->integer('approval_level')->default(1);
            $table->integer('approval_order')->default(1);
            
            // Статус утверждения
            $table->string('status', 50)->default('pending'); // pending, approved, rejected, skipped
            
            // Лимиты и условия
            $table->decimal('amount_threshold', 15, 2)->nullable(); // до какой суммы может утверждать
            $table->json('conditions')->nullable(); // дополнительные условия
            
            // Решение
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            
            // Уведомления
            $table->timestamp('notified_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index('payment_document_id');
            $table->index('approver_user_id');
            $table->index('status');
            $table->index(['payment_document_id', 'approval_level', 'approval_order']);
            $table->index(['approver_user_id', 'status']);
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

