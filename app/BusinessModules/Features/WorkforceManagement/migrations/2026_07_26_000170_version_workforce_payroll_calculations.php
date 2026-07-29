<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_payroll_calculation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 32);
            $table->char('source_hash', 64);
            $table->string('formula_version', 80);
            $table->unsignedInteger('source_row_count');
            $table->unsignedInteger('blocking_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->foreignId('built_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'payroll_period_id', 'version'],
                'workforce_payroll_calculation_version_unique',
            );
            $table->unique(
                ['organization_id', 'id'],
                'workforce_payroll_calculation_version_org_id_unique',
            );
            $table->index(
                ['organization_id', 'payroll_period_id', 'status', 'version'],
                'workforce_payroll_calculation_current_idx',
            );
        });

        DB::statement(
            "ALTER TABLE workforce_payroll_calculation_versions
             ADD CONSTRAINT workforce_payroll_calculation_status_check
             CHECK (status IN ('built', 'validated', 'locked'))",
        );

        Schema::create('workforce_payroll_calculation_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('calculation_version_id');
            $table->foreignId('source_row_id')->constrained('workforce_payroll_source_rows')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('source_type', 80);
            $table->decimal('hours', 18, 4);
            $table->decimal('amount', 24, 4);
            $table->char('currency', 3)->nullable();
            $table->jsonb('source_refs');
            $table->char('row_hash', 64);

            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'calculation_version_id', 'source_row_id'],
                'workforce_payroll_calculation_source_unique',
            );
            $table->index(
                ['organization_id', 'calculation_version_id', 'employee_id', 'project_id'],
                'workforce_payroll_calculation_source_dimensions_idx',
            );
        });

        Schema::create('workforce_payroll_calculation_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('calculation_version_id');
            $table->foreignId('source_issue_id')->constrained('workforce_payroll_validation_issues')->restrictOnDelete();
            $table->string('severity', 40);
            $table->string('issue_code', 120);
            $table->foreignId('employee_id')->nullable()->constrained('workforce_employees')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('audit_ref');
            $table->char('row_hash', 64);

            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'calculation_version_id', 'source_issue_id'],
                'workforce_payroll_calculation_issue_unique',
            );
            $table->index(
                ['organization_id', 'calculation_version_id', 'severity', 'issue_code'],
                'workforce_payroll_calculation_issue_severity_idx',
            );
        });

        Schema::create('payroll_readiness_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->string('row_key', 128);
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('calculation_version_id');
            $table->unsignedInteger('calculation_version');
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->string('employee_name');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name')->nullable();
            $table->string('source_type', 80);
            $table->foreignId('source_row_id')->constrained('workforce_payroll_source_rows')->restrictOnDelete();
            $table->decimal('hours', 18, 4);
            $table->decimal('amount', 24, 4);
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->string('issue_code', 120)->nullable();
            $table->string('severity', 40)->nullable();
            $table->string('status', 32);
            $table->jsonb('source_refs');
            $table->jsonb('audit_refs')->default('[]');
            $table->jsonb('row_payload');

            $table->foreign(['organization_id', 'snapshot_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_report_snapshots')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'calculation_version_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_payroll_calculation_versions')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'payroll_readiness_snapshot_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'status', 'row_key'],
                'payroll_readiness_snapshot_status_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'employee_id', 'row_key'],
                'payroll_readiness_snapshot_employee_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_readiness_snapshot_rows');
        Schema::dropIfExists('workforce_payroll_calculation_issues');
        Schema::dropIfExists('workforce_payroll_calculation_source_rows');
        Schema::dropIfExists('workforce_payroll_calculation_versions');
    }
};
