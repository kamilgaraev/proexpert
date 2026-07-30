<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_report_owner_facts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('source_table', 96);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('operation', 16);
            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('sequence');
            $table->jsonb('payload');
            $table->char('row_hash', 64);

            $table->unique(
                ['organization_id', 'source_table', 'source_id', 'sequence'],
                'workforce_owner_facts_sequence_unique',
            );
            $table->index(
                ['organization_id', 'source_table', 'source_id', 'recorded_at', 'id'],
                'workforce_owner_facts_temporal_idx',
            );
            $table->index(
                ['organization_id', 'project_id', 'recorded_at'],
                'workforce_owner_facts_scope_idx',
            );
        });

        Schema::create('workforce_report_owner_fact_eligibility', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->string('source_table', 120);
            $table->timestampTz('eligible_from');
            $table->unsignedBigInteger('source_row_count');
            $table->char('source_hash', 64);
            $table->primary(
                ['organization_id', 'source_table'],
                'workforce_owner_fact_eligibility_primary',
            );
        });

        Schema::create('workforce_report_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('report_code', 64);
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
                ['organization_id', 'report_code', 'generated_at'],
                'workforce_report_snapshots_org_code_generated_idx',
            );
            $table->index(
                ['organization_id', 'report_code', 'scope_hash', 'period_from', 'period_to', 'as_of'],
                'workforce_report_snapshots_exact_tuple_idx',
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
CREATE FUNCTION workforce_report_capture_owner_fact() RETURNS trigger AS $$
DECLARE
    owner_row jsonb;
    owner_org bigint;
    owner_id bigint;
    owner_project bigint;
    owner_operation text;
    owner_recorded_at timestamptz;
    owner_sequence bigint;
BEGIN
    owner_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    owner_org := (owner_row->>'organization_id')::bigint;
    owner_id := (owner_row->>'id')::bigint;
    owner_project := NULLIF(owner_row->>'project_id', '')::bigint;
    owner_operation := CASE WHEN TG_OP = 'DELETE' THEN 'delete' ELSE 'upsert' END;
    owner_recorded_at := clock_timestamp();
    SELECT COALESCE(MAX(sequence), 0) + 1
      INTO owner_sequence
      FROM workforce_report_owner_facts
     WHERE organization_id = owner_org
       AND source_table = TG_TABLE_NAME
       AND source_id = owner_id;

    INSERT INTO workforce_report_owner_facts (
        organization_id,
        source_table,
        source_id,
        project_id,
        operation,
        recorded_at,
        sequence,
        payload,
        row_hash
    ) VALUES (
        owner_org,
        TG_TABLE_NAME,
        owner_id,
        owner_project,
        owner_operation,
        owner_recorded_at,
        owner_sequence,
        owner_row,
        repeat(md5(owner_row::text), 2)
    );

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_report_guard_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'immutable workforce report snapshot';
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_report_initialize_owner_fact_eligibility() RETURNS trigger AS $$
DECLARE
    owner_table text;
BEGIN
    FOREACH owner_table IN ARRAY ARRAY[
        'workforce_staff_units',
        'workforce_departments',
        'workforce_positions',
        'workforce_employees',
        'workforce_employee_assignments',
        'workforce_work_schedules',
        'workforce_work_schedule_days',
        'workforce_absences',
        'workforce_absence_types',
        'workforce_attendance_corrections',
        'workforce_attendance_scan_events',
        'time_tracking_labor_rate_versions',
        'time_entries',
        'completed_works',
        'schedule_tasks',
        'work_types',
        'measurement_units',
        'contractors',
        'project_schedules',
        'projects'
    ] LOOP
        INSERT INTO workforce_report_owner_fact_eligibility (
            organization_id,
            source_table,
            eligible_from,
            source_row_count,
            source_hash
        ) VALUES (
            NEW.id,
            owner_table,
            clock_timestamp(),
            0,
            repeat(md5(''), 2)
        );
    END LOOP;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER organizations_report_owner_fact_eligibility
AFTER INSERT ON organizations
FOR EACH ROW EXECUTE FUNCTION workforce_report_initialize_owner_fact_eligibility();

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

        $ownerTables = [
            'workforce_staff_units',
            'workforce_departments',
            'workforce_positions',
            'workforce_employees',
            'workforce_employee_assignments',
            'workforce_work_schedules',
            'workforce_work_schedule_days',
            'workforce_absences',
            'workforce_absence_types',
            'workforce_attendance_corrections',
            'workforce_attendance_scan_events',
            'time_tracking_labor_rate_versions',
            'time_entries',
            'completed_works',
            'schedule_tasks',
            'work_types',
            'measurement_units',
            'contractors',
            'project_schedules',
            'projects',
        ];
        foreach ($ownerTables as $table) {
            DB::statement("LOCK TABLE {$table} IN SHARE ROW EXCLUSIVE MODE");
            $eligibleFrom = now();
            DB::statement(
                "CREATE TRIGGER {$table}_report_owner_fact
                 AFTER INSERT OR UPDATE OR DELETE ON {$table}
                 FOR EACH ROW EXECUTE FUNCTION workforce_report_capture_owner_fact()",
            );
            DB::statement(
                "INSERT INTO workforce_report_owner_facts (
                    organization_id,
                    source_table,
                    source_id,
                    project_id,
                    operation,
                    recorded_at,
                    sequence,
                    payload,
                    row_hash
                 )
                 SELECT organization_id,
                        ?,
                        id,
                        NULLIF(to_jsonb(owner_row)->>'project_id', '')::bigint,
                        'upsert',
                        ?,
                        1,
                        to_jsonb(owner_row),
                        repeat(md5(to_jsonb(owner_row)::text), 2)
                 FROM {$table} owner_row",
                [$table, $eligibleFrom],
            );
            DB::statement(
                "INSERT INTO workforce_report_owner_fact_eligibility (
                    organization_id,
                    source_table,
                    eligible_from,
                    source_row_count,
                    source_hash
                 )
                 SELECT organization_record.id,
                        ?,
                        ?,
                        COUNT(owner_fact.source_id),
                        repeat(md5(COALESCE(
                            string_agg(owner_fact.row_hash, '' ORDER BY owner_fact.source_id),
                            ''
                        )), 2)
                 FROM organizations organization_record
                 LEFT JOIN workforce_report_owner_facts owner_fact
                   ON owner_fact.organization_id = organization_record.id
                  AND owner_fact.source_table = ?
                  AND owner_fact.recorded_at = ?
                 GROUP BY organization_record.id
                 ON CONFLICT (organization_id, source_table)
                 DO UPDATE SET
                     eligible_from = EXCLUDED.eligible_from,
                     source_row_count = EXCLUDED.source_row_count,
                     source_hash = EXCLUDED.source_hash",
                [$table, $eligibleFrom, $table, $eligibleFrom],
            );
        }
        DB::unprepared(
            <<<'SQL'
CREATE FUNCTION workforce_report_guard_history_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'immutable workforce report history';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER workforce_report_owner_facts_append_only
BEFORE UPDATE OR DELETE ON workforce_report_owner_facts
FOR EACH ROW EXECUTE FUNCTION workforce_report_guard_history_append_only();

CREATE TRIGGER workforce_report_owner_fact_eligibility_append_only
BEFORE UPDATE OR DELETE ON workforce_report_owner_fact_eligibility
FOR EACH ROW EXECUTE FUNCTION workforce_report_guard_history_append_only();
SQL,
        );
    }

    public function down(): void
    {
        foreach ([
            'workforce_staff_units',
            'workforce_departments',
            'workforce_positions',
            'workforce_employees',
            'workforce_employee_assignments',
            'workforce_work_schedules',
            'workforce_work_schedule_days',
            'workforce_absences',
            'workforce_absence_types',
            'workforce_attendance_corrections',
            'workforce_attendance_scan_events',
            'time_tracking_labor_rate_versions',
            'time_entries',
            'completed_works',
            'schedule_tasks',
            'work_types',
            'measurement_units',
            'contractors',
            'project_schedules',
            'projects',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_report_owner_fact ON {$table}");
        }
        DB::unprepared(
            <<<'SQL'
DROP TRIGGER IF EXISTS organizations_report_owner_fact_eligibility ON organizations;
DROP TRIGGER IF EXISTS workforce_report_owner_fact_eligibility_append_only ON workforce_report_owner_fact_eligibility;
DROP TRIGGER IF EXISTS workforce_report_owner_facts_append_only ON workforce_report_owner_facts;
DROP FUNCTION IF EXISTS workforce_report_guard_history_append_only();
DROP FUNCTION IF EXISTS workforce_report_initialize_owner_fact_eligibility();
DROP TRIGGER IF EXISTS attendance_execution_snapshot_rows_immutable ON attendance_execution_snapshot_rows;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_rows_immutable ON workforce_capacity_snapshot_rows;
DROP TRIGGER IF EXISTS workforce_report_snapshots_immutable ON workforce_report_snapshots;
DROP FUNCTION IF EXISTS workforce_report_guard_immutable();
DROP FUNCTION IF EXISTS workforce_report_capture_owner_fact();
SQL,
        );
        Schema::dropIfExists('attendance_execution_snapshot_rows');
        Schema::dropIfExists('workforce_capacity_snapshot_rows');
        Schema::dropIfExists('workforce_report_snapshots');
        Schema::dropIfExists('workforce_report_owner_fact_eligibility');
        Schema::dropIfExists('workforce_report_owner_facts');
    }
};
