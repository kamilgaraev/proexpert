<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('schedule_tasks')->onDelete('cascade');
            $table->foreignId('schedule_id')->constrained('project_schedules')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('assigned_by_user_id')->constrained('users')->onDelete('restrict');
            
            // Тип ресурса и его идентификатор
            $table->enum('resource_type', ['user', 'equipment', 'material', 'external_resource'])
                  ->comment('Тип ресурса');
            
            // Полиморфная связь для ресурса
            $table->unsignedBigInteger('resource_id')->comment('ID ресурса');
            $table->string('resource_model')->comment('Модель ресурса (User, Equipment, Material и т.д.)');
            
            // Альтернативно можно использовать отдельные поля для каждого типа ресурса
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('material_id')->nullable()->constrained('materials')->onDelete('cascade');
            $table->string('equipment_name')->nullable()->comment('Название оборудования');
            $table->string('external_resource_name')->nullable()->comment('Название внешнего ресурса');
            
            // Назначение и объемы
            $table->decimal('allocated_units', 8, 2)->default(1)->comment('Выделенные единицы ресурса');
            $table->decimal('allocated_hours', 8, 2)->nullable()->comment('Выделенные часы работы');
            $table->decimal('actual_hours', 8, 2)->default(0)->comment('Фактически отработанные часы');
            
            // Процент загрузки ресурса на задаче (100% = полная занятость)
            $table->decimal('allocation_percent', 5, 2)->default(100)->comment('Процент загрузки ресурса');
            
            // Даты назначения
            $table->date('assignment_start_date')->comment('Дата начала назначения');
            $table->date('assignment_end_date')->comment('Дата окончания назначения');
            
            // Стоимость
            $table->decimal('cost_per_hour', 10, 2)->nullable()->comment('Стоимость за час');
            $table->decimal('cost_per_unit', 10, 2)->nullable()->comment('Стоимость за единицу');
            $table->decimal('total_planned_cost', 15, 2)->nullable()->comment('Общая плановая стоимость');
            $table->decimal('total_actual_cost', 15, 2)->default(0)->comment('Общая фактическая стоимость');
            
            // Статус назначения
            $table->enum('assignment_status', [
                'planned', 'confirmed', 'in_progress', 'completed', 'cancelled', 'on_hold'
            ])->default('planned')->comment('Статус назначения ресурса');
            
            // Приоритет ресурса на данной задаче
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])
                  ->default('normal')
                  ->comment('Приоритет ресурса');
            
            // Роль ресурса в задаче
            $table->string('role')->nullable()->comment('Роль ресурса в задаче (например, "ведущий инженер")');
            
            // Требования к ресурсу
            $table->json('requirements')->nullable()->comment('Требования к ресурсу (навыки, квалификация и т.д.)');
            
            // Настройки календаря работы
            $table->json('working_calendar')->nullable()->comment('Рабочий календарь ресурса');
            $table->decimal('daily_working_hours', 4, 2)->default(8)->comment('Рабочих часов в день');
            
            // Конфликты ресурсов
            $table->boolean('has_conflicts')->default(false)->comment('Есть ли конфликты с другими назначениями');
            $table->json('conflict_details')->nullable()->comment('Детали конфликтов');
            
            // Дополнительная информация
            $table->text('notes')->nullable()->comment('Заметки по назначению');
            $table->json('allocation_details')->nullable()->comment('Детали распределения ресурса по времени');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы для оптимизации
            $table->index(['task_id', 'resource_type']);
            $table->index(['schedule_id', 'resource_type']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['user_id', 'assignment_status']);
            $table->index(['material_id']);
            $table->index(['assignment_start_date', 'assignment_end_date']);
            $table->index(['assignment_status', 'priority']);
            $table->index('has_conflicts');
            
            // Составной индекс для полиморфной связи
            $table->index(['resource_type', 'resource_id', 'resource_model'], 'idx_polymorphic_resource');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_resources');
    }
}; 