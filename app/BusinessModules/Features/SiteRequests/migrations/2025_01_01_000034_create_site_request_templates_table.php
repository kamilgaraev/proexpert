<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы шаблонов заявок
     */
    public function up(): void
    {
        Schema::create('site_request_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->comment('Создатель шаблона')
                ->constrained('users')
                ->onDelete('cascade');

            $table->string('name', 255)
                ->comment('Название шаблона');

            $table->text('description')
                ->nullable()
                ->comment('Описание шаблона');

            $table->string('request_type', 50)
                ->comment('Тип заявки: material/personnel/equipment...');

            $table->jsonb('template_data')
                ->comment('Все поля заявки в JSON формате');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Активен ли шаблон');

            $table->integer('usage_count')
                ->default(0)
                ->comment('Сколько раз использовали');

            $table->timestamps();

            // Индексы
            $table->index('organization_id', 'idx_site_request_templates_org');
            $table->index('user_id', 'idx_site_request_templates_user');
            $table->index('request_type', 'idx_site_request_templates_type');
            $table->index('is_active', 'idx_site_request_templates_active');
            $table->index('usage_count', 'idx_site_request_templates_usage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_request_templates');
    }
};

