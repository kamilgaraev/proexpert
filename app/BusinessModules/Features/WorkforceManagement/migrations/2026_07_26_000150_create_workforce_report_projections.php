<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workforce_report_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('report_code', 64);
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 80);
            $table->string('source_schema_version', 80);
            $table->string('freshness_status', 32);
            $table->string('quality_status', 32);
            $table->string('reconciliation_status', 32);
            $table->jsonb('totals');
            $table->jsonb('row_schema');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('source_refs');
            $table->unsignedInteger('row_count')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(
                ['organization_id', 'report_code', 'generated_at'],
                'workforce_report_snapshots_org_code_generated_idx',
            );
            $table->index(
                ['organization_id', 'source_hash'],
                'workforce_report_snapshots_org_source_idx',
            );
        });

        Schema::create('workforce_capacity_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->string('row_key', 128);
            $table->date('month');
            $table->foreignId('staff_unit_id')->constrained('workforce_staff_units')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('workforce_departments')->restrictOnDelete();
            $table->string('department_name');
            $table->foreignId('position_id')->constrained('workforce_positions')->restrictOnDelete();
            $table->string('position_name');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name')->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->date('rate_as_of');
            $table->decimal('planned_fte', 18, 4);
            $table->decimal('assigned_fte', 18, 4);
            $table->decimal('vacancy_fte', 18, 4);
            $table->decimal('overstaffing_fte', 18, 4);
            $table->decimal('vacancy_percent', 18, 4)->nullable();
            $table->decimal('planned_capacity_hours', 18, 4);
            $table->decimal('capacity_hours', 18, 4);
            $table->string('rate_type', 40);
            $table->decimal('rate', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('period_cost_run_rate', 24, 4)->nullable();
            $table->jsonb('quality_warnings')->default('[]');
            $table->jsonb('source_refs');
            $table->jsonb('row_payload');

            $table->foreign(['organization_id', 'snapshot_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_report_snapshots')
                ->cascadeOnDelete();
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'workforce_capacity_snapshot_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'position_id', 'row_key'],
                'workforce_capacity_snapshot_project_position_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'vacancy_fte', 'row_key'],
                'workforce_capacity_snapshot_vacancy_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'month', 'department_id', 'position_id', 'project_id'],
                'workforce_capacity_snapshot_dimensions_idx',
            );
        });

        Schema::create('attendance_execution_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->string('row_key', 128);
            $table->date('work_date');
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->string('employee_name');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('site_name')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('shift')->nullable();
            $table->char('close_version', 64);
            $table->string('status', 40);
            $table->decimal('eligible_hours', 18, 4);
            $table->decimal('present_hours', 18, 4);
            $table->decimal('approved_absence_hours', 18, 4);
            $table->decimal('unexplained_absence_hours', 18, 4);
            $table->decimal('overtime_hours', 18, 4);
            $table->decimal('late_hours', 18, 4);
            $table->decimal('early_hours', 18, 4);
            $table->decimal('execution_percent', 18, 4)->nullable();
            $table->decimal('correction_rate', 18, 4);
            $table->boolean('violation');
            $table->jsonb('source_refs');
            $table->jsonb('audit_refs')->default('[]');
            $table->jsonb('row_payload');

            $table->foreign(['organization_id', 'snapshot_id'])
                ->references(['organization_id', 'id'])
                ->on('workforce_report_snapshots')
                ->cascadeOnDelete();
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'attendance_execution_snapshot_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'work_date', 'project_id', 'row_key'],
                'attendance_execution_snapshot_date_project_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'unexplained_absence_hours', 'row_key'],
                'attendance_execution_snapshot_absence_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'work_date', 'employee_id', 'project_id', 'site_id', 'shift_id'],
                'attendance_execution_snapshot_dimensions_idx',
            );
        });

        DB::unprepared(
            <<<'SQL'
CREATE FUNCTION workforce_report_guard_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'immutable workforce report snapshot';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER workforce_report_snapshots_immutable
BEFORE UPDATE OR DELETE ON workforce_report_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_report_guard_immutable();

CREATE TRIGGER workforce_capacity_snapshot_rows_immutable
BEFORE UPDATE OR DELETE ON workforce_capacity_snapshot_rows
FOR EACH ROW EXECUTE FUNCTION workforce_report_guard_immutable();

CREATE TRIGGER attendance_execution_snapshot_rows_immutable
BEFORE UPDATE OR DELETE ON attendance_execution_snapshot_rows
FOR EACH ROW EXECUTE FUNCTION workforce_report_guard_immutable();
SQL,
        );
    }

    public function down(): void
    {
        DB::unprepared(
            <<<'SQL'
DROP TRIGGER IF EXISTS attendance_execution_snapshot_rows_immutable ON attendance_execution_snapshot_rows;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_rows_immutable ON workforce_capacity_snapshot_rows;
DROP TRIGGER IF EXISTS workforce_report_snapshots_immutable ON workforce_report_snapshots;
DROP FUNCTION IF EXISTS workforce_report_guard_immutable();
SQL,
        );
        Schema::dropIfExists('attendance_execution_snapshot_rows');
        Schema::dropIfExists('workforce_capacity_snapshot_rows');
        Schema::dropIfExists('workforce_report_snapshots');
    }
};
