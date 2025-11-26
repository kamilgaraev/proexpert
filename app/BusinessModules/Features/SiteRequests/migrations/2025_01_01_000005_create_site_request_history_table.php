<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы истории изменений заявок (audit log)
     */
    public function up(): void
    {
        Schema::create('site_request_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_request_id')
                ->constrained('site_requests')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->comment('Кто внес изменение')
                ->constrained('users')
                ->onDelete('cascade');

            $table->string('action', 50)
                ->comment('Тип действия: created, status_changed, assigned, updated, deleted...');

            $table->jsonb('old_value')
                ->nullable()
                ->comment('Старое значение');

            $table->jsonb('new_value')
                ->nullable()
                ->comment('Новое значение');

            $table->text('notes')
                ->nullable()
                ->comment('Комментарий к изменению');

            $table->timestamp('created_at')
                ->useCurrent();

            // Индексы
            $table->index('site_request_id', 'idx_site_request_history_request');
            $table->index('user_id', 'idx_site_request_history_user');
            $table->index('action', 'idx_site_request_history_action');
            $table->index('created_at', 'idx_site_request_history_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_request_history');
    }
};

