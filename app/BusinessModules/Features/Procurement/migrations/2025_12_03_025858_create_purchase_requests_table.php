<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создание таблицы заявок на закупку
     * Связь с SiteRequest (заявка с объекта)
     */
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();

            // Связи
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('site_request_id')
                ->nullable()
                ->constrained('site_requests')
                ->onDelete('set null')
                ->comment('Связь с заявкой с объекта');

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null')
                ->comment('Исполнитель заявки на закупку');

            // Основные поля
            $table->string('request_number')->unique()->comment('Номер заявки на закупку');
            $table->string('status', 50)->default('draft')->comment('Статус заявки');
            $table->text('notes')->nullable()->comment('Примечания');

            // Метаданные
            $table->json('metadata')->nullable()->comment('Дополнительные данные');

            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index(['organization_id', 'status']);
            $table->index('site_request_id');
            $table->index('assigned_to');
            $table->index('request_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
