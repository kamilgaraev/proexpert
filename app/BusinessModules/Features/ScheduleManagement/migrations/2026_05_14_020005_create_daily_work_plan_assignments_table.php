<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_work_plan_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('project_schedules')->cascadeOnDelete();
            $table->foreignId('daily_work_plan_id')->constrained('daily_work_plans')->cascadeOnDelete();
            $table->foreignId('lookahead_plan_task_id')->constrained('lookahead_plan_tasks')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->constrained('schedule_tasks')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('construction_journal_entries')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->decimal('completed_quantity', 14, 4)->nullable();
            $table->decimal('planned_work_hours', 10, 2)->nullable();
            $table->decimal('actual_work_hours', 10, 2)->nullable();
            $table->string('status', 40)->default('planned');
            $table->text('failure_reason')->nullable();
            $table->text('fact_comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['daily_work_plan_id', 'lookahead_plan_task_id']);
            $table->index(['organization_id', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_work_plan_assignments');
    }
};
