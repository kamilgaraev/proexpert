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
        Schema::create('system_analysis_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('system_analysis_reports')->onDelete('cascade');
            $table->foreignId('previous_report_id')->nullable()->constrained('system_analysis_reports')->onDelete('set null');
            $table->json('changes')->nullable(); // Изменения между анализами
            $table->json('comparison')->nullable(); // Детальное сравнение
            $table->timestamps();
            
            // Индексы
            $table->index('report_id');
            $table->index('previous_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_analysis_history');
    }
};

