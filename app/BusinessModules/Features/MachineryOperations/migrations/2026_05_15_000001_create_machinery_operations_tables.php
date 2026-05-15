<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('machinery_id')->nullable()->constrained('machinery')->nullOnDelete();
            $table->foreignId('current_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('current_schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->string('asset_code', 80);
            $table->string('name');
            $table->string('inventory_number', 120)->nullable();
            $table->string('ownership_type', 40)->default('owned');
            $table->string('status', 40)->default('available');
            $table->decimal('operating_cost_per_hour', 15, 2)->default(0);
            $table->string('fuel_type', 80)->nullable();
            $table->decimal('fuel_consumption_rate', 12, 3)->nullable();
            $table->decimal('meter_hours', 12, 2)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'asset_code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('machinery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('active');
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->decimal('planned_hours', 10, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('machinery_shift_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('machinery_assignments')->nullOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('report_date');
            $table->string('status', 40)->default('draft');
            $table->decimal('planned_hours', 10, 2)->default(0);
            $table->decimal('actual_hours', 10, 2)->default(0);
            $table->decimal('fuel_consumed', 12, 3)->default(0);
            $table->decimal('meter_start', 12, 2)->nullable();
            $table->decimal('meter_end', 12, 2)->nullable();
            $table->text('work_description')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'project_id', 'report_date']);
        });

        Schema::create('machinery_downtimes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('shift_report_id')->nullable()->constrained('machinery_shift_reports')->nullOnDelete();
            $table->string('reason', 80);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'reason']);
        });

        Schema::create('machinery_fuel_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->string('fuel_type', 80);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20)->default('l');
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'fuel_type']);
        });

        Schema::create('machinery_maintenance_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number', 80);
            $table->string('title');
            $table->string('maintenance_type', 80)->default('repair');
            $table->string('priority', 40)->default('normal');
            $table->string('status', 40)->default('open');
            $table->text('description')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->text('completion_comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery_maintenance_orders');
        Schema::dropIfExists('machinery_fuel_issues');
        Schema::dropIfExists('machinery_downtimes');
        Schema::dropIfExists('machinery_shift_reports');
        Schema::dropIfExists('machinery_assignments');
        Schema::dropIfExists('machinery_assets');
    }
};
