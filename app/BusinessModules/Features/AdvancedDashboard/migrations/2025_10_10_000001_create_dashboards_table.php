<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            
            // Основные поля
            $table->string('name', 255); // Название дашборда
            $table->text('description')->nullable(); // Описание дашборда
            $table->string('slug', 255)->nullable(); // URL-friendly идентификатор
            
            // Layout и виджеты
            $table->json('layout')->nullable(); // Сетка layout (grid config)
            $table->json('widgets')->nullable(); // Массив виджетов с их настройками
            $table->json('filters')->nullable(); // Глобальные фильтры дашборда
            
            // Настройки
            $table->boolean('is_default')->default(false); // Дашборд по умолчанию
            $table->boolean('is_shared')->default(false); // Расшарен с другими пользователями
            $table->string('template', 100)->nullable(); // Шаблон (admin, finance, technical, custom)
            
            // Права доступа
            $table->json('shared_with')->nullable(); // Массив user_id с которыми расшарен
            $table->enum('visibility', ['private', 'team', 'organization'])->default('private');
            
            // Параметры обновления
            $table->integer('refresh_interval')->default(300); // Интервал обновления в секундах
            $table->boolean('enable_realtime')->default(false); // Real-time обновления через WebSocket
            
            // Метаданные
            $table->unsignedInteger('views_count')->default(0); // Количество просмотров
            $table->timestamp('last_viewed_at')->nullable(); // Последний просмотр
            $table->json('metadata')->nullable(); // Дополнительные метаданные
            
            $table->timestamps();
            $table->softDeletes(); // Мягкое удаление
            
            // Индексы
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            $table->index(['user_id', 'organization_id']);
            $table->index(['organization_id', 'is_shared']);
            $table->index(['user_id', 'is_default']);
            $table->index('slug');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboards');
    }
};

