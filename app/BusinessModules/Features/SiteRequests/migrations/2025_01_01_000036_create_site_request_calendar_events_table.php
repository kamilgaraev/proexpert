<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы календарных событий для заявок
     */
    public function up(): void
    {
        Schema::create('site_request_calendar_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_request_id')
                ->constrained('site_requests')
                ->onDelete('cascade');

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('project_id')
                ->constrained('projects')
                ->onDelete('cascade');

            $table->string('event_type', 50)
                ->comment('Тип события: material_delivery, personnel_work, equipment_rental, deadline');

            $table->string('title', 255)
                ->comment('Заголовок для отображения в календаре');

            $table->text('description')
                ->nullable()
                ->comment('Описание события');

            $table->string('color', 7)
                ->comment('Цвет события в HEX формате');

            $table->date('start_date')
                ->comment('Дата начала события');

            $table->date('end_date')
                ->nullable()
                ->comment('Дата окончания события');

            $table->time('start_time')
                ->nullable()
                ->comment('Время начала');

            $table->time('end_time')
                ->nullable()
                ->comment('Время окончания');

            $table->boolean('all_day')
                ->default(true)
                ->comment('Событие на весь день');

            // Для интеграции с модулем schedule-management
            $table->unsignedBigInteger('schedule_event_id')
                ->nullable()
                ->comment('ID события в модуле schedule-management');

            $table->timestamps();

            // Индексы
            $table->index('organization_id', 'idx_site_request_calendar_org');
            $table->index('project_id', 'idx_site_request_calendar_project');
            $table->index('site_request_id', 'idx_site_request_calendar_request');
            $table->index(['start_date', 'end_date'], 'idx_site_request_calendar_dates');
            $table->index('event_type', 'idx_site_request_calendar_type');
            $table->index('schedule_event_id', 'idx_site_request_calendar_schedule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_request_calendar_events');
    }
};

