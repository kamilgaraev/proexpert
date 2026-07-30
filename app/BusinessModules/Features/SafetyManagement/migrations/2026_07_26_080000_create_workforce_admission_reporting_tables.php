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

        Schema::create('safety_evidence_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('evidence_type', 40);
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestampTz('effective_at');
            $table->jsonb('content');
            $table->char('content_hash', 64);
            $table->boolean('history_complete');
            $table->timestampTz('recorded_at');
            $table->index(
                ['organization_id', 'evidence_type', 'evidence_id', 'effective_at', 'id'],
                'safety_evidence_versions_effective_idx',
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
            $table->jsonb('source_ledger_binding');
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
            $table->foreignId('site_assignment_id')->constrained('safety_site_workforce_assignments')->restrictOnDelete();
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
            $table->foreignId('evidence_version_id')->nullable()->constrained('safety_evidence_versions')->restrictOnDelete();
            $table->char('evidence_hash', 64)->nullable();
            $table->jsonb('evidence_identity')->nullable();
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
        DB::statement("ALTER TABLE safety_site_workforce_assignments ADD CONSTRAINT safety_site_workforce_assignment_no_overlap EXCLUDE USING gist (organization_id WITH =, workforce_assignment_id WITH =, safety_site_id WITH =, (daterange(valid_from, COALESCE(valid_to, 'infinity'::date), '[]')) WITH &&)");
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
        DB::statement("ALTER TABLE safety_admission_rows ADD CONSTRAINT safety_admission_row_evidence_version_check CHECK ((evidence_id IS NULL AND evidence_version_id IS NULL AND evidence_hash IS NULL AND evidence_identity IS NULL) OR (evidence_id IS NOT NULL AND evidence_version_id IS NOT NULL AND evidence_hash ~ '^[a-f0-9]{64}$' AND jsonb_typeof(evidence_identity) = 'object'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION capture_safety_evidence_version() RETURNS trigger AS $$
DECLARE
    source_row jsonb;
    owner_organization_id bigint;
    owner_employee_id bigint;
    owner_project_id bigint;
BEGIN
    IF TG_OP = 'UPDATE' AND (
        OLD.organization_id IS DISTINCT FROM NEW.organization_id
        OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        OR to_jsonb(OLD)->>'project_id' IS DISTINCT FROM to_jsonb(NEW)->>'project_id'
    ) THEN
        source_row := to_jsonb(OLD) || jsonb_build_object('_deleted', true);
        INSERT INTO safety_evidence_versions (
            organization_id, evidence_type, evidence_id, employee_id, project_id,
            effective_at, content, content_hash, history_complete, recorded_at
        ) VALUES (
            OLD.organization_id, TG_ARGV[0], OLD.id, OLD.employee_id,
            NULLIF(to_jsonb(OLD)->>'project_id', '')::bigint, clock_timestamp(), source_row,
            encode(digest(source_row::text, 'sha256'), 'hex'), true, clock_timestamp()
        );
    END IF;
    source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) || jsonb_build_object('_deleted', true) ELSE to_jsonb(NEW) END;
    owner_organization_id := (source_row->>'organization_id')::bigint;
    owner_employee_id := NULLIF(source_row->>'employee_id', '')::bigint;
    owner_project_id := NULLIF(source_row->>'project_id', '')::bigint;
    INSERT INTO safety_evidence_versions (
        organization_id, evidence_type, evidence_id, employee_id, project_id,
        effective_at, content, content_hash, history_complete, recorded_at
    ) VALUES (
        owner_organization_id, TG_ARGV[0], (source_row->>'id')::bigint, owner_employee_id, owner_project_id,
        clock_timestamp(), source_row, encode(digest(source_row::text, 'sha256'), 'hex'), true, clock_timestamp()
    );
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach ([
            'safety_employee_requirements' => 'employee_requirement',
            'safety_training_records' => 'training',
            'safety_medical_exams' => 'medical_exam',
            'safety_ppe_issues' => 'ppe',
        ] as $table => $type) {
            DB::statement("CREATE TRIGGER {$table}_evidence_version AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION capture_safety_evidence_version('{$type}')");
            DB::statement("INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at) SELECT organization_id, '{$type}', id, employee_id, ".($table === 'safety_employee_requirements' ? 'project_id' : 'NULL').", clock_timestamp(), to_jsonb(source), encode(digest(to_jsonb(source)::text, 'sha256'), 'hex'), false, clock_timestamp() FROM {$table} source");
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION capture_safety_briefing_evidence_version() RETURNS trigger AS $$
DECLARE
    participant_row record;
    briefing_row record;
    old_briefing_row record;
    content jsonb;
BEGIN
    IF TG_TABLE_NAME = 'safety_briefing_participants' THEN
        IF TG_OP = 'UPDATE' AND (
            OLD.briefing_id IS DISTINCT FROM NEW.briefing_id
            OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        ) THEN
            SELECT * INTO old_briefing_row FROM safety_briefings WHERE id = OLD.briefing_id;
            content := jsonb_build_object('participant', to_jsonb(OLD), 'briefing', to_jsonb(old_briefing_row), '_deleted', true);
            INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at)
            VALUES (old_briefing_row.organization_id, 'briefing', OLD.id, OLD.employee_id, old_briefing_row.project_id, clock_timestamp(), content, encode(digest(content::text, 'sha256'), 'hex'), true, clock_timestamp());
        END IF;
        SELECT * INTO participant_row FROM safety_briefing_participants WHERE id = COALESCE(NEW.id, OLD.id);
        SELECT * INTO briefing_row FROM safety_briefings WHERE id = COALESCE(NEW.briefing_id, OLD.briefing_id);
        content := jsonb_build_object('participant', COALESCE(to_jsonb(participant_row), to_jsonb(OLD)), 'briefing', to_jsonb(briefing_row));
        IF TG_OP = 'DELETE' THEN content := content || jsonb_build_object('_deleted', true); END IF;
        INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at)
        VALUES (briefing_row.organization_id, 'briefing', COALESCE(NEW.id, OLD.id), COALESCE(NEW.employee_id, OLD.employee_id), briefing_row.project_id, clock_timestamp(), content, encode(digest(content::text, 'sha256'), 'hex'), true, clock_timestamp());
    ELSE
        FOR participant_row IN SELECT * FROM safety_briefing_participants WHERE briefing_id = COALESCE(NEW.id, OLD.id) LOOP
            IF TG_OP = 'UPDATE' AND (
                OLD.organization_id IS DISTINCT FROM NEW.organization_id
                OR OLD.project_id IS DISTINCT FROM NEW.project_id
            ) THEN
                content := jsonb_build_object('participant', to_jsonb(participant_row), 'briefing', to_jsonb(OLD), '_deleted', true);
                INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at)
                VALUES (OLD.organization_id, 'briefing', participant_row.id, participant_row.employee_id, OLD.project_id, clock_timestamp(), content, encode(digest(content::text, 'sha256'), 'hex'), true, clock_timestamp());
            END IF;
            content := jsonb_build_object('participant', to_jsonb(participant_row), 'briefing', CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END);
            IF TG_OP = 'DELETE' THEN content := content || jsonb_build_object('_deleted', true); END IF;
            INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at)
            VALUES (COALESCE(NEW.organization_id, OLD.organization_id), 'briefing', participant_row.id, participant_row.employee_id, COALESCE(NEW.project_id, OLD.project_id), clock_timestamp(), content, encode(digest(content::text, 'sha256'), 'hex'), true, clock_timestamp());
        END LOOP;
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER safety_briefing_participants_evidence_version
AFTER INSERT OR UPDATE OR DELETE ON safety_briefing_participants
FOR EACH ROW EXECUTE FUNCTION capture_safety_briefing_evidence_version();
CREATE TRIGGER safety_briefings_evidence_version
AFTER UPDATE OR DELETE ON safety_briefings
FOR EACH ROW EXECUTE FUNCTION capture_safety_briefing_evidence_version();
INSERT INTO safety_evidence_versions (organization_id, evidence_type, evidence_id, employee_id, project_id, effective_at, content, content_hash, history_complete, recorded_at)
SELECT briefing.organization_id, 'briefing', participant.id, participant.employee_id, briefing.project_id,
       COALESCE(participant.created_at, briefing.created_at), jsonb_build_object('participant', to_jsonb(participant), 'briefing', to_jsonb(briefing)),
       encode(digest(jsonb_build_object('participant', to_jsonb(participant), 'briefing', to_jsonb(briefing))::text, 'sha256'), 'hex'), false, clock_timestamp()
FROM safety_briefing_participants participant
JOIN safety_briefings briefing ON briefing.id = participant.briefing_id;
SQL);
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
CREATE TRIGGER safety_evidence_versions_immutable
BEFORE UPDATE OR DELETE ON safety_evidence_versions
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
        DB::statement('DROP FUNCTION IF EXISTS capture_safety_briefing_evidence_version() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS capture_safety_evidence_version() CASCADE');
        DB::statement('DROP TRIGGER IF EXISTS workforce_assignments_reporting_lifecycle ON workforce_employee_assignments');
        DB::statement('DROP TRIGGER IF EXISTS workforce_employees_reporting_lifecycle ON workforce_employees');
        DB::statement('DROP FUNCTION IF EXISTS safety_capture_workforce_lifecycle()');
        Schema::dropIfExists('safety_admission_rows');
        Schema::dropIfExists('safety_admission_snapshots');
        Schema::dropIfExists('safety_evidence_versions');
        Schema::dropIfExists('safety_workforce_lifecycle_events');
        Schema::dropIfExists('safety_admission_policy_versions');
        Schema::dropIfExists('safety_site_workforce_assignments');
        DB::statement('DROP FUNCTION IF EXISTS safety_admission_immutable_guard()');
    }
};
