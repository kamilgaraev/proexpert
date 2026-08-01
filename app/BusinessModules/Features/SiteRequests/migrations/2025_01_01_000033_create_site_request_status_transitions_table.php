<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы workflow переходов статусов
     */
    public function up(): void
    {
        Schema::create('site_request_status_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('from_status_id')
                ->comment('Исходный статус')
                ->constrained('site_request_statuses')
                ->onDelete('cascade');

            $table->foreignId('to_status_id')
                ->comment('Целевой статус')
                ->constrained('site_request_statuses')
                ->onDelete('cascade');

            $table->string('required_permission', 100)
                ->nullable()
                ->comment('Требуемое право для перехода');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Активен ли переход');

            $table->timestamps();

            // Индексы
            $table->index('organization_id', 'idx_site_request_transitions_org');
            $table->index('from_status_id', 'idx_site_request_transitions_from');
            $table->index('to_status_id', 'idx_site_request_transitions_to');
            $table->index('is_active', 'idx_site_request_transitions_active');

            // Уникальный индекс на пару статусов в рамках организации
            $table->unique(
                ['organization_id', 'from_status_id', 'to_status_id'],
                'idx_site_request_transitions_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_request_status_transitions');
    }
};

