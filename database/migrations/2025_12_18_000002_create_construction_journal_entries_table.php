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
        Schema::create('construction_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')
                ->constrained('construction_journals')
                ->cascadeOnDelete()
                ->comment('Журнал работ');
            $table->foreignId('schedule_task_id')
                ->nullable()
                ->constrained('schedule_tasks')
                ->nullOnDelete()
                ->comment('Задача графика (необязательно)');
            $table->date('entry_date')
                ->comment('Дата записи');
            $table->integer('entry_number')
                ->comment('Номер записи по порядку');
            $table->text('work_description')
                ->comment('Описание выполненных работ');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])
                ->default('draft')
                ->comment('Статус записи');
            
            // Ответственные лица
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Создатель записи');
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Утвердивший запись');
            $table->timestamp('approved_at')
                ->nullable()
                ->comment('Дата утверждения');
            
            // Условия производства работ
            $table->json('weather_conditions')
                ->nullable()
                ->comment('Погодные условия (температура, осадки, ветер)');
            $table->text('problems_description')
                ->nullable()
                ->comment('Описание проблем и задержек');
            $table->text('safety_notes')
                ->nullable()
                ->comment('Вопросы техники безопасности');
            $table->text('visitors_notes')
                ->nullable()
                ->comment('Визиты представителей заказчика, надзора');
            $table->text('quality_notes')
                ->nullable()
                ->comment('Отметки о качестве работ');
            $table->text('rejection_reason')
                ->nullable()
                ->comment('Причина отклонения');
            
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index('journal_id');
            $table->index('schedule_task_id');
            $table->index('entry_date');
            $table->index('status');
            $table->index('created_by_user_id');
            $table->index('approved_by_user_id');
            
            // Уникальность номера записи в пределах журнала
            $table->unique(['journal_id', 'entry_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('construction_journal_entries');
    }
};

