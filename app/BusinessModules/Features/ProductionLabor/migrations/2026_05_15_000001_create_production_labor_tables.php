<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_labor_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number', 80);
            $table->string('title');
            $table->string('assignee_type', 40)->default('brigade');
            $table->string('assignee_name')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_finish_date')->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('production_labor_work_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('production_labor_work_orders')->cascadeOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->foreignId('estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->string('name');
            $table->string('unit', 40)->default('unit');
            $table->decimal('planned_quantity', 18, 4)->default(0);
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('unit_rate', 18, 2)->default(0);
            $table->decimal('planned_hours', 18, 2)->default(0);
            $table->decimal('hour_rate', 18, 2)->default(0);
            $table->string('pay_basis', 20)->default('volume');
            $table->boolean('requires_safety_permit')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'work_order_id']);
        });

        Schema::create('production_labor_output_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('production_labor_work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_line_id')->constrained('production_labor_work_order_lines')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('hours', 18, 2)->default(0);
            $table->string('status', 40)->default('draft');
            $table->timestampTz('approved_at')->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'project_id', 'work_date']);
            $table->index(['organization_id', 'work_order_line_id', 'status']);
        });

        Schema::create('production_labor_timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('production_labor_work_orders')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('shift_date');
            $table->string('status', 40)->default('draft');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'work_order_id', 'shift_date']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('production_labor_timesheet_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_id')->constrained('production_labor_timesheets')->cascadeOnDelete();
            $table->foreignId('work_order_line_id')->constrained('production_labor_work_order_lines')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('worker_name')->nullable();
            $table->decimal('hours', 18, 2)->default(0);
            $table->string('safety_permit_reference')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'timesheet_id']);
        });

        Schema::create('production_labor_payroll_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained('production_labor_work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_line_id')->constrained('production_labor_work_order_lines')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('accepted_quantity', 18, 4)->default(0);
            $table->decimal('accepted_hours', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 40)->default('prepared');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('payment_payload')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'work_order_line_id', 'period_start', 'period_end']);
            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_labor_payroll_accruals');
        Schema::dropIfExists('production_labor_timesheet_entries');
        Schema::dropIfExists('production_labor_timesheets');
        Schema::dropIfExists('production_labor_output_entries');
        Schema::dropIfExists('production_labor_work_order_lines');
        Schema::dropIfExists('production_labor_work_orders');
    }
};
