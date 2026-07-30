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
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        Schema::create('safety_site_workforce_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('safety_site_id')->constrained('safety_sites')->restrictOnDelete();
            $table->foreignId('workforce_assignment_id')->constrained('workforce_employee_assignments')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('mapping_source', 80);
            $table->char('source_hash', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(
                ['organization_id', 'safety_site_id', 'workforce_assignment_id', 'valid_from'],
                'safety_site_workforce_assignment_unique'
            );
            $table->index(
                ['organization_id', 'safety_site_id', 'employee_id', 'valid_from', 'valid_to'],
                'safety_site_workforce_assignment_dates_idx'
            );
        });

        Schema::create('safety_admission_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->string('version', 80);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->jsonb('mandatory_requirements');
            $table->unsignedSmallInteger('expiring_soon_days')->default(30);
            $table->boolean('waiver_evidence_required')->default(true);
            $table->char('source_hash', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['organization_id', 'project_id', 'safety_site_id', 'version'], 'safety_admission_policy_version_unique');
            $table->index(
                ['organization_id', 'project_id', 'safety_site_id', 'effective_from', 'effective_until'],
                'safety_admission_policy_effective_idx'
            );
        });

        Schema::create('safety_workforce_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('event_version');
            $table->string('status', 40);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampTz('occurred_at');
            $table->boolean('history_complete');
            $table->char('source_hash', 64);
            $table->timestampTz('recorded_at');

            $table->unique(
                ['organization_id', 'subject_type', 'subject_id', 'event_version'],
                'safety_workforce_lifecycle_event_unique',
            );
            $table->index(
                ['organization_id', 'subject_type', 'subject_id', 'occurred_at', 'event_version'],
                'safety_workforce_lifecycle_event_order_idx',
            );
        });

        Schema::create('safety_admission_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->jsonb('policy_version_ids');
            $table->char('scope_hash', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 80);
            $table->char('query_hash', 64);
            $table->char('input_hash', 64);
            $table->char('output_hash', 64);
            $table->char('source_hash', 64);
            $table->date('snapshot_date');
            $table->timestampTz('source_watermark');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('evaluated_people')->default(0);
            $table->unsignedBigInteger('admitted_people')->default(0);
            $table->unsignedBigInteger('partial_people')->default(0);
            $table->unsignedBigInteger('not_admitted_people')->default(0);
            $table->unsignedBigInteger('blocker_count')->default(0);
            $table->unsignedBigInteger('expiring_count')->default(0);
            $table->unsignedBigInteger('unverified_count')->default(0);
            $table->unsignedBigInteger('eligible_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at');

            $table->unique(
                ['organization_id', 'scope_hash', 'snapshot_date', 'formula_version', 'source_hash'],
                'safety_admission_snapshot_unique'
            );
            $table->index(['organization_id', 'project_id', 'safety_site_id', 'snapshot_date'], 'safety_admission_snapshot_scope_idx');
        });

        Schema::create('safety_admission_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('safety_admission_snapshots')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->constrained('safety_sites')->restrictOnDelete();
            $table->foreignId('workforce_assignment_id')->constrained('workforce_employee_assignments')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->date('snapshot_date');
            $table->string('row_type', 24);
            $table->string('row_key', 190);
            $table->string('requirement_code', 80);
            $table->string('requirement_type', 40);
            $table->string('status', 40);
            $table->boolean('mandatory');
            $table->boolean('blocked');
            $table->boolean('verified');
            $table->date('valid_until')->nullable();
            $table->string('evidence_type', 40)->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->jsonb('medical_details')->nullable();
            $table->jsonb('blocker_codes');

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'safety_admission_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'safety_site_id', 'employee_id', 'requirement_code', 'status', 'valid_until', 'row_key'],
                'safety_admission_rows_filter_idx'
            );
            $table->index(['organization_id', 'snapshot_id', 'snapshot_date', 'row_key'], 'safety_admission_rows_sort_idx');
        });

        DB::statement('ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_dates_check CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_source_check CHECK (mapping_source = 'workforce_employee_assignments')");
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_no_overlap EXCLUDE USING gist (organization_id WITH =, workforce_assignment_id WITH =, (daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[]')) WITH &&)");
        DB::statement('ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_requirements_check CHECK (jsonb_typeof(mandatory_requirements) = 'array' AND jsonb_array_length(mandatory_requirements) > 0)");
        DB::statement('CREATE UNIQUE INDEX safety_admission_policy_scope_version_unique ON safety_admission_policy_versions (organization_id, project_id, COALESCE(safety_site_id, 0), version)');
        DB::statement("ALTER TABLE safety_admission_policy_versions ADD CONSTRAINT safety_admission_policy_no_overlap EXCLUDE USING gist (organization_id WITH =, project_id WITH =, (COALESCE(safety_site_id, 0)) WITH =, (daterange(effective_from, COALESCE(effective_until, 'infinity'::date), '[]')) WITH &&)");
        DB::statement("ALTER TABLE safety_admission_snapshots ADD CONSTRAINT safety_admission_snapshot_hashes_check CHECK (scope_hash ~ '^[a-f0-9]{64}$' AND definition_hash ~ '^[a-f0-9]{64}$' AND query_hash ~ '^[a-f0-9]{64}$' AND input_hash ~ '^[a-f0-9]{64}$' AND output_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE safety_admission_snapshots ADD CONSTRAINT safety_admission_snapshot_counts_check CHECK (evaluated_people = admitted_people + partial_people + not_admitted_people AND projected_count <= eligible_count AND row_count = projected_count)');
        DB::statement("ALTER TABLE safety_admission_rows ADD CONSTRAINT safety_admission_row_type_check CHECK (row_type = 'requirement')");
        DB::statement("ALTER TABLE safety_workforce_lifecycle_events ADD CONSTRAINT safety_workforce_lifecycle_subject_check CHECK (subject_type IN ('employee', 'assignment'))");
        DB::statement("ALTER TABLE safety_workforce_lifecycle_events ADD CONSTRAINT safety_workforce_lifecycle_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::unprepared(<<<'SQL'
INSERT INTO safety_workforce_lifecycle_events (
    organization_id, subject_type, subject_id, event_version, status, valid_from, valid_to,
    occurred_at, history_complete, source_hash, recorded_at
)
SELECT organization_id, 'employee', id, 1, employment_status, hire_date, dismissal_date,
       COALESCE(updated_at, created_at), updated_at IS NOT DISTINCT FROM created_at,
       encode(digest(concat_ws('|', organization_id, 'employee', id, 1, employment_status, hire_date, dismissal_date, COALESCE(updated_at, created_at), updated_at IS NOT DISTINCT FROM created_at), 'sha256'), 'hex'),
       clock_timestamp()
FROM workforce_employees;
INSERT INTO safety_workforce_lifecycle_events (
    organization_id, subject_type, subject_id, event_version, status, valid_from, valid_to,
    occurred_at, history_complete, source_hash, recorded_at
)
SELECT organization_id, 'assignment', id, 1, status, valid_from, valid_to,
       COALESCE(updated_at, created_at), updated_at IS NOT DISTINCT FROM created_at,
       encode(digest(concat_ws('|', organization_id, 'assignment', id, 1, status, valid_from, valid_to, COALESCE(updated_at, created_at), updated_at IS NOT DISTINCT FROM created_at), 'sha256'), 'hex'),
       clock_timestamp()
FROM workforce_employee_assignments;

CREATE FUNCTION safety_admission_immutable_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'safety_admission_record_immutable' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER safety_site_workforce_assignments_immutable
BEFORE UPDATE OR DELETE ON safety_site_workforce_assignments
FOR EACH ROW EXECUTE FUNCTION safety_admission_immutable_guard();
CREATE TRIGGER safety_admission_policies_immutable
BEFORE UPDATE OR DELETE ON safety_admission_policy_versions
FOR EACH ROW EXECUTE FUNCTION safety_admission_immutable_guard();
CREATE TRIGGER safety_workforce_lifecycle_events_immutable
BEFORE UPDATE OR DELETE ON safety_workforce_lifecycle_events
FOR EACH ROW EXECUTE FUNCTION safety_admission_immutable_guard();

CREATE FUNCTION safety_capture_workforce_lifecycle() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    lifecycle_subject_type text;
    lifecycle_status text;
    lifecycle_valid_from date;
    lifecycle_valid_to date;
    lifecycle_occurred_at timestamptz;
    lifecycle_version bigint;
    lifecycle_complete boolean;
    previous_complete boolean;
    lifecycle_payload text;
BEGIN
    lifecycle_subject_type := CASE WHEN TG_TABLE_NAME = 'workforce_employees' THEN 'employee' ELSE 'assignment' END;
    lifecycle_status := to_jsonb(NEW)->>'employment_status';
    IF lifecycle_subject_type = 'assignment' THEN
        lifecycle_status := to_jsonb(NEW)->>'status';
        lifecycle_valid_from := (to_jsonb(NEW)->>'valid_from')::date;
        lifecycle_valid_to := (to_jsonb(NEW)->>'valid_to')::date;
    ELSE
        lifecycle_valid_from := (to_jsonb(NEW)->>'hire_date')::date;
        lifecycle_valid_to := (to_jsonb(NEW)->>'dismissal_date')::date;
    END IF;
    lifecycle_occurred_at := COALESCE(NEW.updated_at, NEW.created_at, clock_timestamp());
    SELECT history_complete INTO previous_complete
    FROM safety_workforce_lifecycle_events
    WHERE organization_id = NEW.organization_id
      AND subject_type = lifecycle_subject_type
      AND subject_id = NEW.id
    ORDER BY event_version DESC
    LIMIT 1;
    lifecycle_complete := COALESCE(previous_complete, TG_OP = 'INSERT');
    SELECT COALESCE(MAX(event_version), 0) + 1 INTO lifecycle_version
    FROM safety_workforce_lifecycle_events
    WHERE organization_id = NEW.organization_id
      AND subject_type = lifecycle_subject_type
      AND subject_id = NEW.id;
    lifecycle_payload := concat_ws('|', NEW.organization_id, lifecycle_subject_type, NEW.id, lifecycle_version, lifecycle_status, lifecycle_valid_from, lifecycle_valid_to, lifecycle_occurred_at, lifecycle_complete);
    INSERT INTO safety_workforce_lifecycle_events (
        organization_id, subject_type, subject_id, event_version, status, valid_from, valid_to,
        occurred_at, history_complete, source_hash, recorded_at
    ) VALUES (
        NEW.organization_id, lifecycle_subject_type, NEW.id, lifecycle_version, lifecycle_status,
        lifecycle_valid_from, lifecycle_valid_to, lifecycle_occurred_at, lifecycle_complete,
        encode(digest(lifecycle_payload, 'sha256'), 'hex'), clock_timestamp()
    );
    RETURN NEW;
END;
$$;
CREATE TRIGGER workforce_employees_reporting_lifecycle
AFTER INSERT OR UPDATE OF employment_status, hire_date, dismissal_date ON workforce_employees
FOR EACH ROW EXECUTE FUNCTION safety_capture_workforce_lifecycle();
CREATE TRIGGER workforce_assignments_reporting_lifecycle
AFTER INSERT OR UPDATE OF status, valid_from, valid_to ON workforce_employee_assignments
FOR EACH ROW EXECUTE FUNCTION safety_capture_workforce_lifecycle();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS workforce_assignments_reporting_lifecycle ON workforce_employee_assignments');
        DB::statement('DROP TRIGGER IF EXISTS workforce_employees_reporting_lifecycle ON workforce_employees');
        DB::statement('DROP FUNCTION IF EXISTS safety_capture_workforce_lifecycle()');
        Schema::dropIfExists('safety_admission_rows');
        Schema::dropIfExists('safety_admission_snapshots');
        Schema::dropIfExists('safety_workforce_lifecycle_events');
        Schema::dropIfExists('safety_admission_policy_versions');
        Schema::dropIfExists('safety_site_workforce_assignments');
        DB::statement('DROP FUNCTION IF EXISTS safety_admission_immutable_guard()');
    }
};
