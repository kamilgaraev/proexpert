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
        Schema::create('contract_allocation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained('contract_project_allocations')->onDelete('cascade');
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            
            // Действие: created, updated, deleted
            $table->string('action', 50);
            
            // Данные до изменения (JSON)
            $table->json('old_values')->nullable();
            
            // Данные после изменения (JSON)
            $table->json('new_values')->nullable();
            
            // Причина изменения
            $table->text('reason')->nullable();
            
            // Кто совершил действие
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // IP адрес пользователя
            $table->string('ip_address', 45)->nullable();
            
            // User agent
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            
            // Индексы для производительности
            $table->index(['allocation_id', 'created_at']);
            $table->index(['contract_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_allocation_history');
    }
};
