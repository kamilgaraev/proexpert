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
        Schema::create('schedule_task_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_task_id')->constrained('schedule_tasks')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('duration_days')->comment('Число рабочих дней в этом интервале');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['schedule_task_id', 'start_date', 'end_date'], 'idx_task_intervals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_task_intervals');
    }
};
