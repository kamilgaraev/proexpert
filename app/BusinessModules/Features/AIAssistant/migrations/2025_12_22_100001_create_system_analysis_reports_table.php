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
        Schema::create('system_analysis_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->enum('analysis_type', ['single_project', 'all_projects'])->default('single_project');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('ai_model', 100)->nullable();
            $table->integer('tokens_used')->default(0);
            $table->decimal('cost', 10, 4)->default(0);
            $table->json('sections')->nullable(); // Какие разделы анализировались
            $table->json('results')->nullable(); // Полные результаты анализа
            $table->integer('overall_score')->nullable(); // Общая оценка 0-100
            $table->enum('overall_status', ['good', 'warning', 'critical'])->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Индексы для быстрого поиска
            $table->index(['organization_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index('status');
            $table->index('analysis_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_analysis_reports');
    }
};

