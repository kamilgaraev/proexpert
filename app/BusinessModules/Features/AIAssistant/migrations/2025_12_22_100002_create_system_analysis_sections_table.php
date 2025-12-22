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
        Schema::create('system_analysis_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('system_analysis_reports')->onDelete('cascade');
            $table->enum('section_type', [
                'budget',
                'schedule',
                'materials',
                'workers',
                'contracts',
                'risks',
                'performance',
                'recommendations'
            ]);
            $table->json('data')->nullable(); // Собранные данные для этого раздела
            $table->text('analysis')->nullable(); // Текст анализа от AI
            $table->integer('score')->nullable(); // Оценка 0-100
            $table->enum('status', ['good', 'warning', 'critical'])->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->nullable();
            $table->json('recommendations')->nullable(); // Рекомендации для этого раздела
            $table->text('summary')->nullable(); // Краткое резюме
            $table->timestamps();
            
            // Индексы
            $table->index(['report_id', 'section_type']);
            $table->index('section_type');
            $table->index('score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_analysis_sections');
    }
};

