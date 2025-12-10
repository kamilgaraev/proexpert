<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создание таблицы audit log для отслеживания операций модуля закупок
     */
    public function up(): void
    {
        Schema::create('procurement_audit_logs', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null')
                ->comment('Пользователь, выполнивший действие');

            // Polymorphic связь с сущностью
            $table->morphs('auditable', 'auditable_idx');

            // Основные поля
            $table->string('action', 50)->comment('Действие: created, updated, approved, rejected, etc.');
            $table->json('old_values')->nullable()->comment('Старые значения полей');
            $table->json('new_values')->nullable()->comment('Новые значения полей');
            
            // Технические данные
            $table->string('ip_address', 45)->nullable()->comment('IP адрес пользователя');
            $table->text('user_agent')->nullable()->comment('User agent браузера');
            $table->text('notes')->nullable()->comment('Дополнительные заметки');

            $table->timestamps();

            // Индексы
            $table->index(['organization_id', 'created_at']);
            $table->index('action');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procurement_audit_logs');
    }
};

