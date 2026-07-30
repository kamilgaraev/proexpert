<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('time_tracking_labor_rate_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('rate_type', 40);
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3)->nullable();
            $table->date('valid_from');
            $table->date('valid_to_exclusive')->nullable();
            $table->string('status', 32)->default('approved');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'employee_id', 'version'], 'time_tracking_labor_rate_version_unique');
            $table->index(
                ['organization_id', 'employee_id', 'valid_from', 'valid_to_exclusive', 'status'],
                'time_tracking_labor_rate_effective_idx',
            );
        });

        Schema::create('project_labor_cost_report_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('scope_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('as_of');
            $table->date('period_from');
            $table->date('period_to');
            $table->boolean('management_pnl_eligible')->default(false);
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
                ['organization_id', 'generated_at'],
                'project_labor_cost_snapshots_org_generated_idx',
            );
            $table->index(
                ['organization_id', 'scope_hash', 'period_from', 'period_to', 'as_of'],
                'project_labor_cost_snapshots_exact_tuple_idx',
            );
            $table->index(
                ['organization_id', 'source_hash'],
                'project_labor_cost_snapshots_org_source_idx',
            );
        });

        Schema::create('project_labor_cost_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->string('row_key', 128);
            $table->foreignId('time_entry_id')->constrained('time_entries')->restrictOnDelete();
            $table->date('work_date');
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->string('employee_name');
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('project_name');
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contractor_name')->nullable();
            $table->foreignId('task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->string('task_name')->nullable();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->string('work_type_name')->nullable();
            $table->boolean('billable');
            $table->foreignId('accepted_work_id')->nullable()->constrained('completed_works')->nullOnDelete();
            $table->decimal('accepted_units', 18, 4)->nullable();
            $table->string('accepted_unit', 40)->nullable();
            $table->decimal('planned_hours', 18, 4)->nullable();
            $table->decimal('approved_hours', 18, 4);
            $table->decimal('billable_hours', 18, 4);
            $table->decimal('billable_percent', 18, 4);
            $table->foreignId('rate_version_id')->nullable()->constrained('time_tracking_labor_rate_versions')->nullOnDelete();
            $table->decimal('rate', 18, 4)->nullable();
            $table->decimal('cost', 24, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('hours_variance', 18, 4)->nullable();
            $table->decimal('cost_per_accepted_unit', 24, 4)->nullable();
            $table->jsonb('quality_warnings')->default('[]');
            $table->jsonb('source_refs');
            $table->jsonb('row_payload');

            $table->foreign(['organization_id', 'snapshot_id'])
                ->references(['organization_id', 'id'])
                ->on('project_labor_cost_report_snapshots')
                ->cascadeOnDelete();
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'project_labor_cost_snapshot_row_unique');
            $table->unique(['organization_id', 'snapshot_id', 'time_entry_id'], 'project_labor_cost_snapshot_entry_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'work_date', 'row_key'],
                'project_labor_cost_snapshot_project_date_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'contractor_id', 'accepted_work_id', 'row_key'],
                'project_labor_cost_snapshot_contractor_work_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'cost', 'row_key'],
                'project_labor_cost_snapshot_cost_idx',
            );
        });

        DB::unprepared(
            <<<'SQL'
CREATE FUNCTION time_tracking_report_guard_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME IN (
        'project_labor_cost_report_snapshots',
        'project_labor_cost_snapshot_rows'
    ) OR (TG_TABLE_NAME = 'time_tracking_labor_rate_versions' AND OLD.status = 'approved') THEN
        RAISE EXCEPTION 'immutable time tracking report source';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER project_labor_cost_report_snapshots_immutable
BEFORE UPDATE OR DELETE ON project_labor_cost_report_snapshots
FOR EACH ROW EXECUTE FUNCTION time_tracking_report_guard_immutable();

CREATE TRIGGER project_labor_cost_snapshot_rows_immutable
BEFORE UPDATE OR DELETE ON project_labor_cost_snapshot_rows
FOR EACH ROW EXECUTE FUNCTION time_tracking_report_guard_immutable();

CREATE TRIGGER time_tracking_labor_rate_versions_immutable
BEFORE UPDATE OR DELETE ON time_tracking_labor_rate_versions
FOR EACH ROW EXECUTE FUNCTION time_tracking_report_guard_immutable();
SQL,
        );
    }

    public function down(): void
    {
        DB::unprepared(
            <<<'SQL'
DROP TRIGGER IF EXISTS time_tracking_labor_rate_versions_immutable ON time_tracking_labor_rate_versions;
DROP TRIGGER IF EXISTS project_labor_cost_snapshot_rows_immutable ON project_labor_cost_snapshot_rows;
DROP TRIGGER IF EXISTS project_labor_cost_report_snapshots_immutable ON project_labor_cost_report_snapshots;
DROP FUNCTION IF EXISTS time_tracking_report_guard_immutable();
SQL,
        );
        Schema::dropIfExists('project_labor_cost_snapshot_rows');
        Schema::dropIfExists('project_labor_cost_report_snapshots');
        Schema::dropIfExists('time_tracking_labor_rate_versions');
    }
};
