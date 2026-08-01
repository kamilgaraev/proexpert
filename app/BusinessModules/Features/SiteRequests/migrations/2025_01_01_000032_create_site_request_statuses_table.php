<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы настраиваемых статусов заявок
     */
    public function up(): void
    {
        Schema::create('site_request_statuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->string('slug', 50)
                ->comment('Уникальный код статуса: draft, pending, approved...');

            $table->string('name', 100)
                ->comment('Название: Черновик, Ожидает обработки...');

            $table->text('description')
                ->nullable()
                ->comment('Описание статуса');

            $table->string('color', 7)
                ->nullable()
                ->comment('Цвет статуса в HEX формате: #FF5733');

            $table->string('icon', 50)
                ->nullable()
                ->comment('Иконка FontAwesome');

            $table->boolean('is_initial')
                ->default(false)
                ->comment('Является ли начальным статусом');

            $table->boolean('is_final')
                ->default(false)
                ->comment('Является ли конечным статусом');

            $table->integer('display_order')
                ->default(0)
                ->comment('Порядок отображения');

            $table->timestamps();

            // Уникальный индекс на slug в рамках организации
            $table->unique(['organization_id', 'slug'], 'idx_site_request_statuses_org_slug');
            $table->index('organization_id', 'idx_site_request_statuses_org');
            $table->index('is_initial', 'idx_site_request_statuses_initial');
            $table->index('is_final', 'idx_site_request_statuses_final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_request_statuses');
    }
};

