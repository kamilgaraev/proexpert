<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dashboard_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            
            // Основные поля
            $table->string('name', 255); // Название расписания
            $table->text('description')->nullable(); // Описание
            
            // Параметры расписания
            $table->string('frequency', 50); // daily, weekly, monthly, custom
            $table->string('cron_expression', 100)->nullable(); // Для custom расписания
            $table->time('time_of_day')->default('09:00:00'); // Время отправки
            $table->json('days_of_week')->nullable(); // [1,2,3,4,5] для weekly
            $table->integer('day_of_month')->nullable(); // 1-31 для monthly
            
            // Форматы экспорта
            $table->json('export_formats'); // pdf, excel, both
            $table->boolean('attach_excel')->default(false);
            $table->boolean('attach_pdf')->default(true);
            
            // Получатели
            $table->json('recipients'); // Массив email адресов
            $table->json('cc_recipients')->nullable(); // CC копии
            $table->text('email_subject')->nullable(); // Тема письма (шаблон)
            $table->text('email_body')->nullable(); // Тело письма (шаблон)
            
            // Параметры отчета
            $table->json('filters')->nullable(); // Фильтры для применения
            $table->json('widgets')->nullable(); // Какие виджеты включить (null = все)
            $table->boolean('include_raw_data')->default(false); // Включить сырые данные
            
            // Статус
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable(); // Следующий запуск
            $table->timestamp('last_run_at')->nullable(); // Последний запуск
            $table->enum('last_run_status', ['success', 'failed', 'pending'])->nullable();
            $table->text('last_run_error')->nullable(); // Ошибка последнего запуска
            $table->integer('run_count')->default(0); // Сколько раз запускался
            $table->integer('success_count')->default(0); // Сколько раз успешно
            $table->integer('failure_count')->default(0); // Сколько раз с ошибкой
            
            // Ограничения
            $table->date('start_date')->nullable(); // Начало отправки
            $table->date('end_date')->nullable(); // Конец отправки
            $table->integer('max_runs')->nullable(); // Максимальное количество запусков
            
            // Метаданные
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->foreign('dashboard_id')->references('id')->on('dashboards')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            $table->index(['organization_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
            $table->index(['is_active', 'next_run_at']);
            $table->index('next_run_at');
            $table->index('last_run_at');
            $table->index('frequency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};

