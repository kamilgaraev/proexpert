<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('schedule_tasks')->onDelete('cascade');
            $table->foreignId('schedule_id')->constrained('project_schedules')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            
            // Основная информация о вехе
            $table->string('name')->comment('Название вехи');
            $table->text('description')->nullable()->comment('Описание вехи');
            
            // Тип вехи
            $table->enum('milestone_type', [
                'project_start', 'project_end', 'phase_start', 'phase_end', 
                'deliverable', 'approval', 'payment', 'custom'
            ])->default('custom')->comment('Тип вехи');
            
            // Даты
            $table->date('target_date')->comment('Целевая дата достижения вехи');
            $table->date('baseline_date')->nullable()->comment('Базовая дата (эталонный план)');
            $table->date('actual_date')->nullable()->comment('Фактическая дата достижения');
            
            // Статус вехи
            $table->enum('status', ['pending', 'in_progress', 'achieved', 'missed', 'cancelled'])
                  ->default('pending')
                  ->comment('Статус вехи');
            
            // Приоритет и критичность
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])
                  ->default('normal')
                  ->comment('Приоритет вехи');
            
            $table->boolean('is_critical')->default(false)->comment('Критическая веха');
            $table->boolean('is_external')->default(false)->comment('Внешняя веха (зависит от внешних факторов)');
            
            // Критерии достижения
            $table->json('completion_criteria')->nullable()->comment('Критерии завершения вехи');
            $table->decimal('completion_percent', 5, 2)->default(0)->comment('Процент завершения критериев');
            
            // Ответственные лица
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('stakeholders')->nullable()->comment('Заинтересованные стороны');
            
            // Связанные документы и deliverables
            $table->json('deliverables')->nullable()->comment('Связанные результаты/документы');
            $table->json('approvals_required')->nullable()->comment('Требуемые согласования');
            
            // Уведомления
            $table->json('notification_settings')->nullable()->comment('Настройки уведомлений');
            $table->integer('alert_days_before')->default(0)->comment('За сколько дней до целевой даты отправлять уведомления');
            
            // Риски и проблемы
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])
                  ->default('low')
                  ->comment('Уровень риска недостижения вехи');
            
            $table->text('risk_description')->nullable()->comment('Описание рисков');
            $table->text('mitigation_plan')->nullable()->comment('План по снижению рисков');
            
            // Финансовая информация
            $table->decimal('budget_impact', 15, 2)->nullable()->comment('Влияние на бюджет');
            $table->boolean('triggers_payment')->default(false)->comment('Запускает ли оплату');
            $table->decimal('payment_amount', 15, 2)->nullable()->comment('Сумма платежа');
            
            // Дополнительные поля
            $table->text('notes')->nullable()->comment('Заметки');
            $table->json('custom_fields')->nullable()->comment('Пользовательские поля');
            $table->json('tags')->nullable()->comment('Теги для группировки');
            
            // Связь с внешними системами
            $table->string('external_id')->nullable()->comment('ID во внешней системе');
            $table->json('external_data')->nullable()->comment('Данные из внешних систем');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы для оптимизации
            $table->index(['task_id', 'status']);
            $table->index(['schedule_id', 'milestone_type']);
            $table->index(['target_date', 'status']);
            $table->index(['responsible_user_id', 'status']);
            $table->index(['is_critical', 'status']);
            $table->index(['milestone_type', 'priority']);
            $table->index('triggers_payment');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_milestones');
    }
};
