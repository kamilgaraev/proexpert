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
        Schema::create('application_errors', function (Blueprint $table) {
            $table->id();
            
            // ============================================
            // ГРУППИРОВКА (дедупликация одинаковых ошибок)
            // ============================================
            $table->string('error_hash', 64)->unique();
            $table->string('error_group', 500);
            
            // ============================================
            // ДЕТАЛИ ОШИБКИ
            // ============================================
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('file', 500);
            $table->integer('line');
            $table->text('stack_trace');
            
            // ============================================
            // КОНТЕКСТ ПРИЛОЖЕНИЯ
            // ============================================
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->onDelete('set null');
            
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            $table->string('module', 100)->nullable();
            
            // ============================================
            // HTTP КОНТЕКСТ
            // ============================================
            $table->text('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // ============================================
            // ДОПОЛНИТЕЛЬНЫЕ МЕТАДАННЫЕ
            // ============================================
            $table->jsonb('context')->nullable();
            
            // ============================================
            // СЧЕТЧИКИ И ВРЕМЯ
            // ============================================
            $table->integer('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            
            // ============================================
            // УПРАВЛЕНИЕ ОШИБКАМИ
            // ============================================
            $table->enum('status', ['unresolved', 'resolved', 'ignored'])->default('unresolved');
            $table->enum('severity', ['warning', 'error', 'critical'])->default('error');
            
            // ============================================
            // TIMESTAMPS
            // ============================================
            $table->timestamps();
            
            // ============================================
            // ИНДЕКСЫ
            // ============================================
            $table->index('error_hash');
            $table->index('status');
            $table->index('severity');
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'last_seen_at']);
            $table->index('last_seen_at');
            $table->index('module');
            $table->index('exception_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_errors');
    }
};

