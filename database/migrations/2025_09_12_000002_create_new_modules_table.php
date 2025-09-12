<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version', 20);
            $table->enum('type', ['core', 'feature', 'addon', 'service', 'extension']);
            $table->enum('billing_model', ['free', 'subscription', 'one_time', 'usage_based', 'freemium']);
            $table->string('category', 100)->default('general');
            $table->text('description')->nullable();
            
            // Конфигурация цен
            $table->json('pricing_config')->nullable();
            
            // Функции и права
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            
            // Зависимости
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
            
            // Метаданные
            $table->string('class_name')->nullable();
            $table->string('config_file')->nullable();
            $table->string('icon')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            
            // Автообновление
            $table->timestamp('last_scanned_at')->nullable();
            
            $table->timestamps();
            
            // Индексы
            $table->index('slug');
            $table->index('type');
            $table->index('is_active');
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
