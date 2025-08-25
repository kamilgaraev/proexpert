<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            
            $table->string('name')->comment('Название графика');
            $table->text('description')->nullable()->comment('Описание графика');
            
            // Плановые даты
            $table->date('planned_start_date')->comment('Планируемая дата начала');
            $table->date('planned_end_date')->comment('Планируемая дата окончания');
            
            // Базовые даты (эталонный план для сравнения отклонений)
            $table->date('baseline_start_date')->nullable()->comment('Базовая дата начала');
            $table->date('baseline_end_date')->nullable()->comment('Базовая дата окончания');
            $table->timestamp('baseline_saved_at')->nullable()->comment('Когда сохранен базовый план');
            $table->foreignId('baseline_saved_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Фактические даты
            $table->date('actual_start_date')->nullable()->comment('Фактическая дата начала');
            $table->date('actual_end_date')->nullable()->comment('Фактическая дата окончания');
            
            // Статус и настройки
            $table->enum('status', ['draft', 'active', 'paused', 'completed', 'cancelled'])
                  ->default('draft')
                  ->comment('Статус графика');
            
            $table->boolean('is_template')->default(false)->comment('Является ли шаблоном');
            $table->string('template_name')->nullable()->comment('Название шаблона');
            $table->text('template_description')->nullable()->comment('Описание шаблона');
            
            // Настройки расчета
            $table->json('calculation_settings')->nullable()->comment('Настройки автоматического расчета');
            $table->json('display_settings')->nullable()->comment('Настройки отображения');
            
            // Метаданные критического пути
            $table->boolean('critical_path_calculated')->default(false)->comment('Рассчитан ли критический путь');
            $table->timestamp('critical_path_updated_at')->nullable()->comment('Когда обновлен критический путь');
            $table->integer('critical_path_duration_days')->nullable()->comment('Длительность критического пути в днях');
            
            // Финансовая информация
            $table->decimal('total_estimated_cost', 15, 2)->nullable()->comment('Общая плановая стоимость');
            $table->decimal('total_actual_cost', 15, 2)->nullable()->comment('Общая фактическая стоимость');
            
            // Прогресс
            $table->decimal('overall_progress_percent', 5, 2)->default(0)->comment('Общий прогресс в процентах');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы для оптимизации запросов
            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['is_template', 'organization_id']);
            $table->index('critical_path_calculated');
            $table->index(['planned_start_date', 'planned_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_schedules');
    }
};
