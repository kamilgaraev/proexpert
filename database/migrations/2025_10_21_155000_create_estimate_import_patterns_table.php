<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_import_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Характеристики файла
            $table->integer('rows_before_header')->nullable()->comment('Количество строк до заголовка');
            $table->integer('header_row')->comment('Номер строки заголовков');
            $table->integer('total_rows')->comment('Общее количество строк в файле');
            $table->integer('columns_count')->comment('Количество колонок');
            
            // Паттерн заголовка
            $table->json('header_keywords')->nullable()->comment('Ключевые слова найденные в заголовках');
            $table->boolean('has_merged_cells')->default(false);
            $table->boolean('is_multiline_header')->default(false);
            
            // Структура файла
            $table->json('file_structure')->nullable()->comment('Общая структура файла (JSON)');
            
            // Коррективы пользователя
            $table->integer('user_selected_row')->nullable()->comment('Строка выбранная пользователем вручную');
            $table->json('user_corrections')->nullable()->comment('Коррективы column mapping');
            
            // Метрики успешности
            $table->float('auto_detection_confidence')->nullable()->comment('Уверенность автоопределения');
            $table->boolean('was_correct')->default(true)->comment('Было ли автоопределение правильным');
            
            // Частота использования паттерна
            $table->integer('usage_count')->default(1)->comment('Сколько раз этот паттерн встречался');
            $table->timestamp('last_used_at')->nullable();
            
            $table->timestamps();
            
            // Индексы для быстрого поиска паттернов
            $table->index(['organization_id', 'header_row']);
            $table->index(['organization_id', 'columns_count']);
            $table->index(['was_correct', 'usage_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_import_patterns');
    }
};

