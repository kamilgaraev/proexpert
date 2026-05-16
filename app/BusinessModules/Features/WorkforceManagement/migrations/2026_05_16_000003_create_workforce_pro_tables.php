<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('workforce_departments')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('workforce_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('workforce_work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('schedule_type', 40)->default('weekly');
            $table->decimal('hours_per_day', 5, 2)->default(8);
            $table->jsonb('week_pattern')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('workforce_staff_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('workforce_departments')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('workforce_positions')->restrictOnDelete();
            $table->string('code', 80);
            $table->decimal('headcount', 8, 2)->default(1);
            $table->decimal('rate', 8, 4)->default(1);
            $table->decimal('base_salary', 18, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('workforce_employment_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->string('contract_number', 120);
            $table->date('contract_date');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 40)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'contract_number']);
        });

        Schema::create('workforce_employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('staff_unit_id')->constrained('workforce_staff_units')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('workforce_departments')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('workforce_positions')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_schedule_id')->nullable()->constrained('workforce_work_schedules')->nullOnDelete();
            $table->decimal('rate', 8, 4)->default(1);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 40)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'employee_id', 'valid_from', 'valid_to'], 'workforce_assignment_employee_dates_idx');
        });

        Schema::create('workforce_work_schedule_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_id')->constrained('workforce_work_schedules')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('day_type', 40)->default('work');
            $table->decimal('planned_hours', 5, 2)->default(8);
            $table->string('comment')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'work_schedule_id', 'work_date'], 'workforce_schedule_day_unique');
        });

        Schema::create('workforce_absence_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('affects_payroll')->default(true);
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('workforce_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('absence_type_id')->constrained('workforce_absence_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 40)->default('draft');
            $table->string('comment')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'employee_id', 'start_date', 'end_date']);
        });

        Schema::create('workforce_business_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('destination');
            $table->string('purpose')->nullable();
            $table->string('status', 40)->default('draft');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('workforce_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->string('order_number', 120);
            $table->date('order_date');
            $table->string('order_type', 80);
            $table->string('status', 40)->default('draft');
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'order_number']);
        });

        Schema::create('workforce_payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 40)->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'period_start', 'period_end']);
        });

        Schema::create('workforce_payroll_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('production_labor_work_orders')->nullOnDelete();
            $table->foreignId('work_order_line_id')->nullable()->constrained('production_labor_work_order_lines')->nullOnDelete();
            $table->foreignId('timesheet_entry_id')->nullable()->constrained('production_labor_timesheet_entries')->nullOnDelete();
            $table->date('work_date');
            $table->string('source_type', 80);
            $table->decimal('hours', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'payroll_period_id', 'timesheet_entry_id'], 'workforce_source_unique_timesheet');
        });

        Schema::create('workforce_payroll_validation_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->cascadeOnDelete();
            $table->string('severity', 40);
            $table->string('issue_code', 120);
            $table->string('message');
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'payroll_period_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_payroll_validation_issues');
        Schema::dropIfExists('workforce_payroll_source_rows');
        Schema::dropIfExists('workforce_payroll_periods');
        Schema::dropIfExists('workforce_orders');
        Schema::dropIfExists('workforce_business_trips');
        Schema::dropIfExists('workforce_absences');
        Schema::dropIfExists('workforce_absence_types');
        Schema::dropIfExists('workforce_work_schedule_days');
        Schema::dropIfExists('workforce_employee_assignments');
        Schema::dropIfExists('workforce_employment_contracts');
        Schema::dropIfExists('workforce_staff_units');
        Schema::dropIfExists('workforce_work_schedules');
        Schema::dropIfExists('workforce_positions');
        Schema::dropIfExists('workforce_departments');
    }
};
