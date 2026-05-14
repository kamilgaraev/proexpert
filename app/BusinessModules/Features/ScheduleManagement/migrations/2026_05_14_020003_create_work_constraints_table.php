<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_constraints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('project_schedules')->cascadeOnDelete();
            $table->foreignId('lookahead_plan_task_id')->constrained('lookahead_plan_tasks')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->constrained('schedule_tasks')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('constraint_type', 80);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('soft');
            $table->string('status', 40)->default('open');
            $table->date('due_date')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->foreignId('overridden_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['schedule_id', 'severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_constraints');
    }
};
