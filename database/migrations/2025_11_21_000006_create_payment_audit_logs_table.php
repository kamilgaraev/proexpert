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
        Schema::create('payment_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Документ
            $table->foreignId('payment_document_id')->nullable()->constrained('payment_documents')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            // Действие
            $table->string('action', 50); // created, updated, submitted, approved, rejected, paid, cancelled
            $table->string('entity_type', 100); // PaymentDocument, PaymentApproval, PaymentTransaction
            $table->unsignedBigInteger('entity_id')->nullable();
            
            // Пользователь
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('user_name')->nullable(); // Сохраняем имя на случай удаления пользователя
            $table->string('user_role')->nullable();
            
            // Изменения
            $table->json('old_values')->nullable(); // Старые значения
            $table->json('new_values')->nullable(); // Новые значения
            $table->json('changed_fields')->nullable(); // Список измененных полей
            
            // Контекст
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('description')->nullable(); // Человекочитаемое описание
            
            // Метаданные
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at');
            
            // Индексы
            $table->index('payment_document_id');
            $table->index('organization_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('entity_type');
            $table->index('created_at');
            $table->index(['payment_document_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_audit_logs');
    }
};

