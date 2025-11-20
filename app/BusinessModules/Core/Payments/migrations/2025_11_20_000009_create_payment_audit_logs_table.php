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
            
            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            
            $table->foreignId('payment_document_id')
                ->nullable()
                ->constrained('payment_documents')
                ->onDelete('cascade');
            
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            // Действие
            $table->string('action'); // created, updated, submitted, approved, rejected, paid, cancelled
            $table->string('entity_type'); // PaymentDocument, PaymentApproval, PaymentTransaction
            $table->unsignedBigInteger('entity_id')->nullable();
            
            // Информация о пользователе
            $table->string('user_name')->nullable();
            $table->string('user_role')->nullable();
            
            // Изменения
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable(); // Список измененных полей
            
            // Метаданные
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index('organization_id');
            $table->index('payment_document_id');
            $table->index('user_id');
            $table->index('action');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
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

