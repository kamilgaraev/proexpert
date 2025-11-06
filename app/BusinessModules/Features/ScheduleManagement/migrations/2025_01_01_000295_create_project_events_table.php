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
        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            
            // Связи
            $table->foreignId('project_id')
                ->constrained('projects')
                ->onDelete('cascade');
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');
            $table->foreignId('schedule_id')
                ->nullable()
                ->constrained('project_schedules')
                ->onDelete('set null');
            $table->foreignId('related_task_id')
                ->nullable()
                ->constrained('schedule_tasks')
                ->onDelete('set null');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            
            // Основные поля события
            $table->string('event_type'); // inspection, delivery, meeting, maintenance, weather, other
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            
            // Дата и время
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->integer('duration_minutes')->default(60);
            $table->boolean('is_all_day')->default(false);
            
            // Дата окончания (для многодневных событий)
            $table->date('end_date')->nullable();
            
            // Дополнительные флаги
            $table->boolean('is_blocking')->default(false); // Блокирует ли работы
            $table->string('priority')->default('normal'); // low, normal, high, critical
            $table->string('status')->default('scheduled'); // scheduled, in_progress, completed, cancelled
            
            // Участники и исполнители
            $table->json('participants')->nullable(); // [user_id, user_id, ...]
            $table->json('responsible_users')->nullable(); // Ответственные
            $table->json('organizations')->nullable(); // Сторонние организации (подрядчики, контролирующие органы)
            
            // Напоминания
            $table->integer('reminder_before_hours')->nullable();
            $table->boolean('reminder_sent')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();
            
            // Повторяющиеся события
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_pattern')->nullable(); // daily, weekly, monthly
            $table->json('recurrence_config')->nullable(); // Детали повторения
            $table->foreignId('recurring_parent_id')
                ->nullable()
                ->constrained('project_events')
                ->onDelete('cascade');
            
            // Вложения и заметки
            $table->json('attachments')->nullable(); // Файлы
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            
            // Цвет для отображения в календаре
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index('project_id');
            $table->index('organization_id');
            $table->index('schedule_id');
            $table->index('event_date');
            $table->index('event_type');
            $table->index('status');
            $table->index(['project_id', 'event_date']);
            $table->index(['organization_id', 'event_date']);
            $table->index('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_events');
    }
};

