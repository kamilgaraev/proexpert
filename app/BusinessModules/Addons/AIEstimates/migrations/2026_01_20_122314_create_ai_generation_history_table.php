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
        Schema::create('ai_generation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Входные данные
            $table->text('input_description');
            $table->json('input_parameters')->nullable(); // площадь, тип, регион
            $table->json('uploaded_files')->nullable(); // пути к файлам
            
            // AI ответ
            $table->json('ai_response')->nullable();
            $table->json('matched_positions')->nullable();
            
            // Метаданные
            $table->string('status', 50)->default('pending'); // pending, processing, completed, failed, cancelled
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->text('error_message')->nullable();
            
            // Результат
            $table->json('generated_estimate_draft')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable(); // 0.0000 - 1.0000
            
            // OCR данные
            $table->json('ocr_results')->nullable();
            $table->integer('processing_time_ms')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_generation_history');
    }
};
