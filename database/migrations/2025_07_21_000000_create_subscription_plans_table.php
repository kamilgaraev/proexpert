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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Например, "Старт", "Команда"
            $table->string('slug')->unique(); // Например, "start", "team" - для программной идентификации
            $table->text('description')->nullable(); // Краткое описание тарифа
            $table->decimal('price', 10, 2); // Цена
            $table->string('currency', 3)->default('RUB'); // Валюта (ISO 4217)
            $table->integer('duration_in_days')->default(30); // Длительность подписки в днях (например, 30 для месяца)

            // Лимиты
            $table->integer('max_foremen')->nullable(); // null означает безлимит или не применимо
            $table->integer('max_projects')->nullable();
            $table->integer('max_storage_gb')->nullable();

            $table->json('features')->nullable(); // Дополнительные фичи тарифа в виде JSON
            // (например, ["API Access", "Priority Support"])

            $table->boolean('is_active')->default(true); // Активен ли тариф для выбора пользователями
            $table->integer('display_order')->default(0); // Порядок отображения тарифов
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
}; 