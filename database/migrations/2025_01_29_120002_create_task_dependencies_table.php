<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_task_id')->constrained('schedule_tasks')->onDelete('cascade');
            $table->foreignId('successor_task_id')->constrained('schedule_tasks')->onDelete('cascade');
            $table->foreignId('schedule_id')->constrained('project_schedules')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            
            // Тип зависимости
            $table->enum('dependency_type', ['FS', 'SS', 'FF', 'SF'])
                  ->default('FS')
                  ->comment('Тип зависимости: FS-Finish to Start, SS-Start to Start, FF-Finish to Finish, SF-Start to Finish');
            
            // Лаг (задержка) или опережение (отрицательное значение)
            $table->integer('lag_days')->default(0)->comment('Лаг в днях (положительное значение) или опережение (отрицательное)');
            $table->decimal('lag_hours', 8, 2)->default(0)->comment('Лаг в часах для более точного планирования');
            
            // Тип лага
            $table->enum('lag_type', ['days', 'hours', 'percent'])
                  ->default('days')
                  ->comment('Тип единицы измерения лага');
            
            // Критичность связи
            $table->boolean('is_critical')->default(false)->comment('Находится ли связь на критическом пути');
            $table->boolean('is_hard_constraint')->default(true)->comment('Жесткое ограничение (true) или мягкое (false)');
            
            // Приоритет связи (для разрешения конфликтов)
            $table->integer('priority')->default(0)->comment('Приоритет связи (чем выше число, тем выше приоритет)');
            
            // Дополнительная информация
            $table->text('description')->nullable()->comment('Описание зависимости');
            $table->text('constraint_reason')->nullable()->comment('Причина ограничения');
            
            // Статус связи
            $table->boolean('is_active')->default(true)->comment('Активна ли связь');
            $table->enum('validation_status', ['valid', 'creates_cycle', 'invalid_dates', 'resource_conflict'])
                  ->default('valid')
                  ->comment('Статус валидации связи');
            
            // Дополнительные настройки
            $table->json('advanced_settings')->nullable()->comment('Дополнительные настройки зависимости');
            
            $table->timestamps();
            
            // Индексы для оптимизации запросов
            $table->index(['predecessor_task_id', 'successor_task_id'], 'idx_task_dependency_pair');
            $table->index(['schedule_id', 'is_critical']);
            $table->index(['schedule_id', 'dependency_type']);
            $table->index(['schedule_id', 'is_active']);
            $table->index('validation_status');
            
            // Уникальность пары задач с одним типом зависимости
            $table->unique(['predecessor_task_id', 'successor_task_id', 'dependency_type'], 'unique_task_dependency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
}; 