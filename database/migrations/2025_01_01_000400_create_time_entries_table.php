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
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->comment('Пользователь, который ведет учет времени');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade')->comment('Проект, по которому ведется учет');
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->onDelete('set null')->comment('Тип работы (опционально)');
            $table->foreignId('task_id')->nullable()->constrained('schedule_tasks')->onDelete('set null')->comment('Конкретная задача из расписания (опционально)');
            
            // Основные поля времени
            $table->date('work_date')->comment('Дата выполнения работы');
            $table->time('start_time')->nullable()->comment('Время начала работы');
            $table->time('end_time')->nullable()->comment('Время окончания работы');
            $table->decimal('hours_worked', 8, 2)->comment('Количество отработанных часов');
            $table->decimal('break_time', 8, 2)->default(0)->comment('Время перерывов в часах');
            
            // Описание работы
            $table->string('title')->comment('Краткое описание выполненной работы');
            $table->text('description')->nullable()->comment('Подробное описание работы');
            
            // Статус и утверждение
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->comment('Статус записи');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null')->comment('Кто утвердил запись');
            $table->timestamp('approved_at')->nullable()->comment('Когда утверждена');
            $table->text('rejection_reason')->nullable()->comment('Причина отклонения');
            
            // Дополнительные поля
            $table->boolean('is_billable')->default(true)->comment('Оплачиваемое время');
            $table->decimal('hourly_rate', 10, 2)->nullable()->comment('Почасовая ставка');
            $table->string('location')->nullable()->comment('Место выполнения работы');
            $table->json('custom_fields')->nullable()->comment('Дополнительные поля');
            $table->text('notes')->nullable()->comment('Заметки');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['organization_id', 'user_id', 'work_date']);
            $table->index(['project_id', 'work_date']);
            $table->index(['work_type_id', 'work_date']);
            $table->index(['status', 'work_date']);
            $table->index('work_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
