<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - создание таблицы условий для ролей (ABAC).
     */
    public function up(): void
    {
        Schema::create('role_conditions', function (Blueprint $table) {
            $table->id();
            
            // Назначение роли, к которому применяются условия
            $table->unsignedBigInteger('assignment_id');
            $table->foreign('assignment_id')->references('id')->on('user_role_assignments')->onDelete('cascade');
            
            // Тип условия
            $table->enum('condition_type', [
                'time',         // Временные ограничения
                'location',     // Географические ограничения  
                'budget',       // Бюджетные лимиты
                'project_count',// Максимальное количество проектов
                'custom'        // Кастомные условия
            ])->index();
            
            // Данные условия (JSON)
            $table->json('condition_data'); // {"max_budget": 500000, "working_hours": "09:00-18:00"}
            
            // Активность условия
            $table->boolean('is_active')->default(true)->index();
            
            // Индексы для оптимизации
            $table->index(['assignment_id']);
            $table->index(['assignment_id', 'is_active']);
            $table->index(['condition_type', 'is_active']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_conditions');
    }
};
