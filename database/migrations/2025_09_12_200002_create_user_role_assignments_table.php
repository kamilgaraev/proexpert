<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - создание таблицы назначений ролей пользователям.
     */
    public function up(): void
    {
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            
            // Пользователь
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Слаг роли (из JSON или кастомная роль)
            $table->string('role_slug', 100)->index();
            
            // Тип роли: system (JSON) или custom (БД)
            $table->enum('role_type', ['system', 'custom'])->default('system');
            
            // Контекст, в котором действует роль
            $table->unsignedBigInteger('context_id');
            $table->foreign('context_id')->references('id')->on('authorization_contexts')->onDelete('cascade');
            
            // Кто назначил роль
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
            
            // Срок действия роли (NULL = бессрочно)
            $table->timestamp('expires_at')->nullable();
            
            // Активность назначения
            $table->boolean('is_active')->default(true)->index();
            
            // Составные индексы для оптимизации запросов
            $table->index(['user_id', 'context_id']);
            $table->index(['role_slug', 'context_id']);
            $table->index(['user_id', 'is_active']);
            
            // Уникальное ограничение: один пользователь не может иметь одну роль дважды в одном контексте
            $table->unique(['user_id', 'role_slug', 'context_id'], 'unique_user_role_context');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
    }
};
