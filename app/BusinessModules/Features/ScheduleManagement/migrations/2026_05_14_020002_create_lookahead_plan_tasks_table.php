<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookahead_plan_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('project_schedules')->cascadeOnDelete();
            $table->foreignId('lookahead_plan_id')->constrained('lookahead_plans')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->constrained('schedule_tasks')->cascadeOnDelete();
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->decimal('planned_work_hours', 10, 2)->nullable();
            $table->string('readiness_status', 40)->default('pending');
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lookahead_plan_id', 'schedule_task_id']);
            $table->index(['organization_id', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookahead_plan_tasks');
    }
};
