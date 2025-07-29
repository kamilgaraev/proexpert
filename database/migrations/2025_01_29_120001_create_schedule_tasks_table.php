<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('project_schedules')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('parent_task_id')->nullable()->constrained('schedule_tasks')->onDelete('cascade');
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->onDelete('set null');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            
            // Основная информация
            $table->string('name')->comment('Название задачи');
            $table->text('description')->nullable()->comment('Описание задачи');
            $table->string('wbs_code')->nullable()->comment('Код WBS (Work Breakdown Structure)');
            
            // Тип задачи
            $table->enum('task_type', ['task', 'milestone', 'summary', 'container'])
                  ->default('task')
                  ->comment('Тип задачи');
            
            // Плановые даты и длительность
            $table->date('planned_start_date')->comment('Планируемая дата начала');
            $table->date('planned_end_date')->comment('Планируемая дата окончания');
            $table->integer('planned_duration_days')->comment('Планируемая длительность в днях');
            $table->decimal('planned_work_hours', 8, 2)->default(0)->comment('Планируемые трудозатраты в часах');
            
            // Базовые даты (для анализа отклонений)
            $table->date('baseline_start_date')->nullable()->comment('Базовая дата начала');
            $table->date('baseline_end_date')->nullable()->comment('Базовая дата окончания');
            $table->integer('baseline_duration_days')->nullable()->comment('Базовая длительность в днях');
            
            // Фактические даты
            $table->date('actual_start_date')->nullable()->comment('Фактическая дата начала');
            $table->date('actual_end_date')->nullable()->comment('Фактическая дата окончания');
            $table->integer('actual_duration_days')->nullable()->comment('Фактическая длительность в днях');
            $table->decimal('actual_work_hours', 8, 2)->default(0)->comment('Фактические трудозатраты в часах');
            
            // Расчетные даты (вычисляются автоматически)
            $table->date('early_start_date')->nullable()->comment('Раннее начало (CPM)');
            $table->date('early_finish_date')->nullable()->comment('Раннее окончание (CPM)');
            $table->date('late_start_date')->nullable()->comment('Позднее начало (CPM)');
            $table->date('late_finish_date')->nullable()->comment('Позднее окончание (CPM)');
            
            // Резервы времени
            $table->integer('total_float_days')->default(0)->comment('Общий резерв времени в днях');
            $table->integer('free_float_days')->default(0)->comment('Свободный резерв времени в днях');
            
            // Критический путь
            $table->boolean('is_critical')->default(false)->comment('Находится ли на критическом пути');
            $table->boolean('is_milestone_critical')->default(false)->comment('Критическая веха');
            
            // Прогресс и статус
            $table->decimal('progress_percent', 5, 2)->default(0)->comment('Прогресс выполнения в процентах');
            $table->enum('status', [
                'not_started', 'in_progress', 'completed', 'cancelled', 'on_hold', 'waiting'
            ])->default('not_started')->comment('Статус задачи');
            
            // Приоритет
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])
                  ->default('normal')
                  ->comment('Приоритет задачи');
            
            // Финансовая информация
            $table->decimal('estimated_cost', 15, 2)->nullable()->comment('Плановая стоимость');
            $table->decimal('actual_cost', 15, 2)->nullable()->comment('Фактическая стоимость');
            $table->decimal('earned_value', 15, 2)->nullable()->comment('Освоенный объем');
            
            // Ресурсы
            $table->json('required_resources')->nullable()->comment('Требуемые ресурсы (JSON)');
            
            // Ограничения
            $table->enum('constraint_type', [
                'none', 'must_start_on', 'must_finish_on', 'start_no_earlier_than', 
                'start_no_later_than', 'finish_no_earlier_than', 'finish_no_later_than'
            ])->default('none')->comment('Тип ограничения даты');
            $table->date('constraint_date')->nullable()->comment('Дата ограничения');
            
            // Дополнительные поля
            $table->json('custom_fields')->nullable()->comment('Пользовательские поля');
            $table->text('notes')->nullable()->comment('Заметки');
            $table->json('tags')->nullable()->comment('Теги для группировки');
            
            // Иерархия для отображения в дереве
            $table->integer('level')->default(0)->comment('Уровень вложенности');
            $table->integer('sort_order')->default(0)->comment('Порядок сортировки');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы для оптимизации
            $table->index(['schedule_id', 'parent_task_id']);
            $table->index(['schedule_id', 'is_critical']);
            $table->index(['schedule_id', 'status']);
            $table->index(['schedule_id', 'task_type']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['work_type_id']);
            $table->index(['planned_start_date', 'planned_end_date']);
            $table->index(['actual_start_date', 'actual_end_date']);
            $table->index(['level', 'sort_order']);
            $table->index('wbs_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_tasks');
    }
}; 