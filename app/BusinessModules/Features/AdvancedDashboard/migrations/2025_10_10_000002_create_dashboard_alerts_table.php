<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dashboard_id')->nullable(); // Может быть привязан к дашборду
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            
            // Основные поля
            $table->string('name', 255); // Название алерта
            $table->text('description')->nullable(); // Описание алерта
            
            // Тип и цель алерта
            $table->string('alert_type', 100); // budget_overrun, deadline_risk, low_stock, etc.
            $table->string('target_entity', 100); // project, contract, material, etc.
            $table->unsignedBigInteger('target_entity_id')->nullable(); // ID целевой сущности
            
            // Условия срабатывания
            $table->json('conditions'); // Условия для проверки (threshold, operator, value)
            $table->string('comparison_operator', 20); // gt, lt, eq, gte, lte
            $table->decimal('threshold_value', 15, 2)->nullable(); // Пороговое значение
            $table->string('threshold_unit', 50)->nullable(); // %, RUB, days, etc.
            
            // Параметры уведомления
            $table->json('notification_channels'); // email, in_app, webhook
            $table->json('recipients')->nullable(); // Список получателей (user_id, email)
            $table->integer('cooldown_minutes')->default(60); // Минимальный интервал между уведомлениями
            
            // Статус
            $table->boolean('is_active')->default(true); // Алерт активен
            $table->boolean('is_triggered')->default(false); // Сработал ли алерт
            $table->timestamp('last_triggered_at')->nullable(); // Когда последний раз сработал
            $table->timestamp('last_checked_at')->nullable(); // Когда последний раз проверялся
            $table->integer('trigger_count')->default(0); // Сколько раз сработал
            
            // Приоритет
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            
            // Метаданные
            $table->json('metadata')->nullable(); // Дополнительные данные
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->foreign('dashboard_id')->references('id')->on('dashboards')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            $table->index(['user_id', 'organization_id']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['alert_type', 'target_entity']);
            $table->index(['target_entity', 'target_entity_id']);
            $table->index('last_checked_at');
            $table->index(['is_triggered', 'last_triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_alerts');
    }
};

