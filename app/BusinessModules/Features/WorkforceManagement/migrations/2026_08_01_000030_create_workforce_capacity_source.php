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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('workforce_timezone', 80)->default('Europe/Moscow');
        });

        Schema::create('workforce_capacity_capture_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('mutation_id', 200);
            $table->string('status', 20);
            $table->char('current_cursor', 64)->nullable();
            $table->unsignedBigInteger('snapshot_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->unique(['organization_id', 'mutation_id'], 'workforce_capacity_capture_request_unique');
        });

        Schema::create('workforce_capacity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('as_of_date');
            $table->date('month_start');
            $table->foreignId('staff_unit_id')->constrained('workforce_staff_units')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('capture_kind', 30);
            $table->string('capture_mutation_id', 200);
            $table->char('capture_cursor', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('service_actor', 80)->nullable();
            $table->string('source_schema_version', 80);
            $table->string('formula_version', 80);
            $table->string('policy_version', 80);
            $table->char('policy_hash', 64);
            $table->jsonb('policy_definition');
            $table->text('policy_canonical');
            $table->decimal('authorized_fte', 12, 4)->nullable();
            $table->decimal('assigned_fte', 12, 4);
            $table->decimal('available_fte', 12, 4);
            $table->decimal('approved_unavailability_fte', 12, 4);
            $table->decimal('open_fte', 12, 4)->nullable();
            $table->decimal('overallocated_fte', 12, 4)->nullable();
            $table->decimal('scheduled_hours', 16, 2)->nullable();
            $table->string('capacity_status', 30);
            $table->jsonb('gap_codes');
            $table->jsonb('source_counts');
            $table->unsignedInteger('item_count');
            $table->char('items_hash', 64);
            $table->text('items_canonical');
            $table->char('state_hash', 64);
            $table->text('state_canonical');
            $table->char('source_hash', 64);
            $table->text('source_canonical');
            $table->timestampTz('captured_at', 6);
            $table->timestampTz('sealed_at', 6)->nullable();
            $table->index(
                ['organization_id', 'month_start', 'staff_unit_id', 'project_id', 'captured_at'],
                'workforce_capacity_snapshot_lookup_idx',
            );
            $table->index(
                ['organization_id', 'month_start', 'project_id'],
                'workforce_capacity_snapshot_drilldown_idx',
            );
        });

        Schema::create('workforce_capacity_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workforce_capacity_snapshot_id')->constrained('workforce_capacity_snapshots')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_unit_id')->constrained('workforce_staff_units')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('month_start');
            $table->unsignedInteger('position');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('source_revision_hash', 64);
            $table->text('source_canonical');
            $table->char('content_hash', 64);
            $table->text('content_canonical');
            $table->jsonb('lineage');
            $table->jsonb('evidence');
            $table->foreignId('sealed_employee_id')->nullable()->constrained('workforce_employees')->restrictOnDelete();
            $table->timestampTz('created_at', 6);
            $table->unique(
                ['workforce_capacity_snapshot_id', 'position'],
                'workforce_capacity_item_position_unique',
            );
            $table->index(
                ['workforce_capacity_snapshot_id', 'position'],
                'workforce_capacity_item_order_idx',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE workforce_capacity_capture_requests
    ADD CONSTRAINT workforce_capacity_capture_status_check
    CHECK (
        status IN ('processing', 'completed')
        AND snapshot_count >= 0
        AND chunk_count >= 0
        AND (current_cursor IS NULL OR current_cursor ~ '^[0-9a-f]{64}$')
        AND ((status = 'processing' AND completed_at IS NULL)
             OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at))
    );

ALTER TABLE workforce_capacity_snapshots
    ADD CONSTRAINT workforce_capacity_snapshot_shape_check
    CHECK (
        source_schema_version = 'workforce-capacity-source.v1'
        AND formula_version = 'workforce-capacity-formula.v1'
        AND policy_version = 'workforce-capacity-policy.v1'
        AND capture_kind IN ('change_capture', 'scheduled_close', 'manual_recompute')
        AND capacity_status IN ('gap', 'understaffed', 'balanced', 'overallocated', 'unavailable')
        AND date_trunc('month', month_start)::date = month_start
        AND date_trunc('month', as_of_date)::date = month_start
        AND assigned_fte >= 0
        AND available_fte >= 0
        AND approved_unavailability_fte >= 0
        AND (authorized_fte IS NULL OR authorized_fte >= 0)
        AND (open_fte IS NULL OR open_fte >= 0)
        AND (overallocated_fte IS NULL OR overallocated_fte >= 0)
        AND (scheduled_hours IS NULL OR scheduled_hours >= 0)
        AND policy_hash ~ '^[0-9a-f]{64}$'
        AND items_hash ~ '^[0-9a-f]{64}$'
        AND state_hash ~ '^[0-9a-f]{64}$'
        AND source_hash ~ '^[0-9a-f]{64}$'
        AND capture_cursor ~ '^[0-9a-f]{64}$'
        AND jsonb_typeof(policy_definition) = 'object'
        AND jsonb_typeof(gap_codes) = 'array'
        AND jsonb_typeof(source_counts) = 'object'
    );

CREATE UNIQUE INDEX workforce_capacity_snapshot_idem_unique
    ON workforce_capacity_snapshots (
        organization_id,
        as_of_date,
        month_start,
        staff_unit_id,
        COALESCE(project_id, 0),
        capture_kind,
        source_hash
    );

CREATE UNIQUE INDEX workforce_capacity_item_source_unique
    ON workforce_capacity_snapshot_items (
        workforce_capacity_snapshot_id,
        source_type,
        COALESCE(source_id, 0),
        content_hash
    );

CREATE FUNCTION workforce_capacity_json_has_forbidden(value jsonb) RETURNS boolean AS $$
DECLARE
    entry record;
BEGIN
    IF jsonb_typeof(value) = 'object' THEN
        FOR entry IN SELECT key, val FROM jsonb_each(value) AS each_value(key, val)
        LOOP
            IF lower(entry.key) = ANY (ARRAY[
                'address', 'base_salary', 'comment', 'destination', 'email', 'first_name',
                'last_name', 'middle_name', 'overtime', 'payroll_amount', 'personnel_number',
                'phone', 'purpose', 'qr_payload', 'salary', 'salary_amount', 'actual_hours'
            ]) OR workforce_capacity_json_has_forbidden(entry.val) THEN
                RETURN true;
            END IF;
        END LOOP;
    ELSIF jsonb_typeof(value) = 'array' THEN
        FOR entry IN SELECT element AS val FROM jsonb_array_elements(value) AS elements(element)
        LOOP
            IF workforce_capacity_json_has_forbidden(entry.val) THEN
                RETURN true;
            END IF;
        END LOOP;
    END IF;

    RETURN false;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

CREATE FUNCTION workforce_capacity_prevent_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity evidence is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_capture_request_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.status = 'completed' THEN
        RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'completed workforce capacity capture is immutable';
    END IF;
    IF NEW.organization_id <> OLD.organization_id
       OR NEW.mutation_id <> OLD.mutation_id
       OR NEW.started_at <> OLD.started_at
       OR NEW.snapshot_count < OLD.snapshot_count
       OR NEW.chunk_count < OLD.chunk_count
       OR (NEW.status = 'completed' AND NEW.completed_at IS NULL) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity capture transition invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_snapshot_insert_guard() RETURNS trigger AS $$
DECLARE
    organization_timezone text;
    expected_state jsonb;
    expected_source jsonb;
BEGIN
    SELECT workforce_timezone INTO organization_timezone
      FROM organizations
     WHERE id = NEW.organization_id;

    IF NOT FOUND
       OR organization_timezone IS NULL
       OR NEW.policy_definition IS DISTINCT FROM NEW.policy_canonical::jsonb
       OR encode(sha256(convert_to(NEW.policy_canonical, 'UTF8')), 'hex') <> NEW.policy_hash
       OR encode(sha256(convert_to(NEW.items_canonical, 'UTF8')), 'hex') <> NEW.items_hash
       OR encode(sha256(convert_to(NEW.state_canonical, 'UTF8')), 'hex') <> NEW.state_hash
       OR encode(sha256(convert_to(NEW.source_canonical, 'UTF8')), 'hex') <> NEW.source_hash
       OR NEW.policy_definition->>'version' <> NEW.policy_version
       OR NEW.policy_definition->>'timezone' <> organization_timezone
       OR NEW.policy_definition->'calendar_precedence' <> '["schedule_day","weekly_pattern","gap"]'::jsonb
       OR NEW.policy_definition->>'missing_schedule_rule' <> 'gap'
       OR NEW.policy_definition->>'project_attribution_rule' <> 'exact_or_null_bucket_no_derived_split'
       OR NEW.policy_definition->'assignment_statuses' <> '["active"]'::jsonb
       OR NEW.policy_definition->'unavailability_statuses' <> '["approved"]'::jsonb
       OR NEW.policy_definition->>'absence_type_rule' <> 'affects_payroll_true_v1'
       OR workforce_capacity_json_has_forbidden(NEW.policy_definition - 'redacted_fields') THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity policy or hash invalid';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM workforce_staff_units AS unit
         WHERE unit.id = NEW.staff_unit_id AND unit.organization_id = NEW.organization_id
    ) OR (NEW.project_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM projects AS project
         WHERE project.id = NEW.project_id AND project.organization_id = NEW.organization_id
    )) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity header lineage invalid';
    END IF;

    IF NEW.capture_kind = 'manual_recompute' THEN
        IF NEW.actor_user_id IS NULL OR NEW.service_actor IS NOT NULL OR NOT EXISTS (
            SELECT 1 FROM organization_user AS membership
             WHERE membership.organization_id = NEW.organization_id
               AND membership.user_id = NEW.actor_user_id
               AND membership.is_active = true
        ) THEN
            RAISE EXCEPTION USING ERRCODE = '42501', MESSAGE = 'manual workforce capacity actor invalid';
        END IF;
    ELSIF NEW.actor_user_id IS NOT NULL
       OR NEW.service_actor NOT IN ('workforce-owner', 'workforce-scheduler') THEN
        RAISE EXCEPTION USING ERRCODE = '42501', MESSAGE = 'system workforce capacity actor invalid';
    END IF;

    IF NEW.available_fte <> GREATEST(NEW.assigned_fte - NEW.approved_unavailability_fte, 0)
       OR (NEW.authorized_fte IS NULL AND (
            NEW.open_fte IS NOT NULL OR NEW.overallocated_fte IS NOT NULL
            OR NOT (NEW.gap_codes ? 'source_contract_missing')
       ))
       OR (NEW.authorized_fte IS NOT NULL AND (
            NEW.open_fte <> GREATEST(NEW.authorized_fte - NEW.assigned_fte, 0)
            OR NEW.overallocated_fte <> GREATEST(NEW.assigned_fte - NEW.authorized_fte, 0)
       ))
       OR ((jsonb_array_length(NEW.gap_codes) > 0) <> (NEW.capacity_status = 'gap')) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity aggregate formula invalid';
    END IF;

    expected_state := jsonb_build_object(
        'organization_id', NEW.organization_id,
        'as_of_date', NEW.as_of_date::text,
        'month_start', NEW.month_start::text,
        'staff_unit_id', NEW.staff_unit_id,
        'project_id', NEW.project_id,
        'capture_kind', NEW.capture_kind,
        'source_schema_version', NEW.source_schema_version,
        'formula_version', NEW.formula_version,
        'policy_hash', NEW.policy_hash,
        'authorized_fte', CASE WHEN NEW.authorized_fte IS NULL THEN NULL ELSE to_char(NEW.authorized_fte, 'FM9999999990.0000') END,
        'assigned_fte', to_char(NEW.assigned_fte, 'FM9999999990.0000'),
        'available_fte', to_char(NEW.available_fte, 'FM9999999990.0000'),
        'approved_unavailability_fte', to_char(NEW.approved_unavailability_fte, 'FM9999999990.0000'),
        'open_fte', CASE WHEN NEW.open_fte IS NULL THEN NULL ELSE to_char(NEW.open_fte, 'FM9999999990.0000') END,
        'overallocated_fte', CASE WHEN NEW.overallocated_fte IS NULL THEN NULL ELSE to_char(NEW.overallocated_fte, 'FM9999999990.0000') END,
        'scheduled_hours', CASE WHEN NEW.scheduled_hours IS NULL THEN NULL ELSE to_char(NEW.scheduled_hours, 'FM99999999999990.00') END,
        'capacity_status', NEW.capacity_status,
        'gap_codes', NEW.gap_codes,
        'source_counts', NEW.source_counts,
        'item_count', NEW.item_count
    );
    expected_source := jsonb_build_object(
        'schema', NEW.source_schema_version,
        'formula', NEW.formula_version,
        'policy_hash', NEW.policy_hash,
        'state_hash', NEW.state_hash,
        'items_hash', NEW.items_hash
    );
    IF NEW.state_canonical::jsonb IS DISTINCT FROM expected_state
       OR NEW.source_canonical::jsonb IS DISTINCT FROM expected_source THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity canonical state invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_item_insert_guard() RETURNS trigger AS $$
DECLARE
    snapshot workforce_capacity_snapshots%ROWTYPE;
    content jsonb;
BEGIN
    SELECT * INTO snapshot FROM workforce_capacity_snapshots WHERE id = NEW.workforce_capacity_snapshot_id;
    content := NEW.content_canonical::jsonb;
    IF NOT FOUND OR snapshot.sealed_at IS NOT NULL
       OR snapshot.organization_id <> NEW.organization_id
       OR snapshot.staff_unit_id <> NEW.staff_unit_id
       OR snapshot.project_id IS DISTINCT FROM NEW.project_id
       OR snapshot.month_start <> NEW.month_start
       OR NEW.source_type NOT IN (
            'staff_unit', 'assignment', 'employee_lifecycle', 'schedule', 'schedule_day',
            'absence', 'business_trip', 'capacity_gap'
       )
       OR NEW.source_revision_hash !~ '^[0-9a-f]{64}$'
       OR NEW.content_hash !~ '^[0-9a-f]{64}$'
       OR encode(sha256(convert_to(NEW.source_canonical, 'UTF8')), 'hex') <> NEW.source_revision_hash
       OR encode(sha256(convert_to(NEW.content_canonical, 'UTF8')), 'hex') <> NEW.content_hash
       OR content->>'type' <> NEW.source_type
       OR NULLIF(content->>'source_id', '')::bigint IS DISTINCT FROM NEW.source_id
       OR content->>'revision' <> NEW.source_revision_hash
       OR content->'lineage' IS DISTINCT FROM NEW.lineage
       OR content->'evidence' IS DISTINCT FROM NEW.evidence
       OR NULLIF(content->>'sealed_employee_id', '')::bigint IS DISTINCT FROM NEW.sealed_employee_id
       OR workforce_capacity_json_has_forbidden(NEW.source_canonical::jsonb)
       OR workforce_capacity_json_has_forbidden(NEW.content_canonical::jsonb) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity evidence payload invalid';
    END IF;

    IF NEW.source_type = 'staff_unit' AND NOT EXISTS (
        SELECT 1 FROM workforce_staff_units AS unit
         WHERE unit.id = NEW.source_id AND unit.organization_id = NEW.organization_id
           AND unit.id = NEW.staff_unit_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity staff unit lineage invalid';
    ELSIF NEW.source_type = 'assignment' AND NOT EXISTS (
        SELECT 1 FROM workforce_employee_assignments AS assignment
         WHERE assignment.id = NEW.source_id
           AND assignment.organization_id = NEW.organization_id
           AND assignment.staff_unit_id = NEW.staff_unit_id
           AND assignment.project_id IS NOT DISTINCT FROM NEW.project_id
           AND assignment.employee_id = NEW.sealed_employee_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity assignment lineage invalid';
    ELSIF NEW.source_type = 'schedule' AND NOT EXISTS (
        SELECT 1 FROM workforce_work_schedules AS schedule
         WHERE schedule.id = NEW.source_id AND schedule.organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity schedule lineage invalid';
    ELSIF NEW.source_type = 'schedule_day' AND NOT EXISTS (
        SELECT 1 FROM workforce_work_schedule_days AS schedule_day
         WHERE schedule_day.id = NEW.source_id AND schedule_day.organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity schedule day lineage invalid';
    ELSIF NEW.source_type = 'absence' AND NOT EXISTS (
        SELECT 1 FROM workforce_absences AS absence
         WHERE absence.id = NEW.source_id AND absence.organization_id = NEW.organization_id
           AND absence.employee_id = NEW.sealed_employee_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity absence lineage invalid';
    ELSIF NEW.source_type = 'business_trip' AND NOT EXISTS (
        SELECT 1 FROM workforce_business_trips AS trip
         WHERE trip.id = NEW.source_id AND trip.organization_id = NEW.organization_id
           AND trip.employee_id = NEW.sealed_employee_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity business trip lineage invalid';
    ELSIF NEW.source_type = 'employee_lifecycle' AND NOT EXISTS (
        SELECT 1 FROM workforce_employees AS employee
         WHERE employee.id = NEW.source_id AND employee.organization_id = NEW.organization_id
           AND employee.id = NEW.sealed_employee_id
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity lifecycle lineage invalid';
    ELSIF NEW.source_type = 'capacity_gap' AND (
        NEW.source_id IS NOT NULL OR NEW.sealed_employee_id IS NOT NULL
        OR NOT (snapshot.gap_codes ? (NEW.evidence->>'gap_code'))
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity gap lineage invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_snapshot_finalize_guard() RETURNS trigger AS $$
DECLARE
    actual_count bigint;
    first_position integer;
    last_position integer;
    actual_items jsonb;
    actual_counts jsonb;
BEGIN
    IF OLD.sealed_at IS NOT NULL
       OR NEW.sealed_at IS NULL
       OR NEW.sealed_at < NEW.captured_at
       OR (to_jsonb(NEW) - 'sealed_at') IS DISTINCT FROM (to_jsonb(OLD) - 'sealed_at') THEN
        RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity snapshot is append-only';
    END IF;

    SELECT COUNT(*), MIN(position), MAX(position),
           COALESCE(jsonb_agg(jsonb_build_object(
               'position', position,
               'type', source_type,
               'content_hash', content_hash
           ) ORDER BY position), '[]'::jsonb)
      INTO actual_count, first_position, last_position, actual_items
      FROM workforce_capacity_snapshot_items
     WHERE workforce_capacity_snapshot_id = NEW.id;

    SELECT jsonb_object_agg(source_type, source_count)
      INTO actual_counts
      FROM (
          SELECT type_list.source_type, COUNT(item.id) AS source_count
            FROM unnest(ARRAY[
                'staff_unit', 'assignment', 'employee_lifecycle', 'schedule',
                'schedule_day', 'absence', 'business_trip', 'capacity_gap'
            ]) AS type_list(source_type)
            LEFT JOIN workforce_capacity_snapshot_items AS item
              ON item.workforce_capacity_snapshot_id = NEW.id
             AND item.source_type = type_list.source_type
           GROUP BY type_list.source_type
      ) AS counts;

    IF actual_count <> NEW.item_count
       OR (actual_count > 0 AND (first_position <> 1 OR last_position <> actual_count))
       OR actual_items IS DISTINCT FROM NEW.items_canonical::jsonb
       OR actual_counts IS DISTINCT FROM NEW.source_counts
       OR EXISTS (
           SELECT 1 FROM jsonb_array_elements_text(NEW.gap_codes) AS gap(code)
            WHERE NOT EXISTS (
                SELECT 1 FROM workforce_capacity_snapshot_items AS item
                 WHERE item.workforce_capacity_snapshot_id = NEW.id
                   AND item.source_type = 'capacity_gap'
                   AND item.evidence->>'gap_code' = gap.code
            )
       )
       OR EXISTS (
           SELECT 1 FROM workforce_capacity_snapshot_items AS item
            WHERE item.workforce_capacity_snapshot_id = NEW.id
              AND item.created_at > NEW.sealed_at
       ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity evidence set incomplete';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_snapshot_commit_guard() RETURNS trigger AS $$
BEGIN
    IF (SELECT sealed_at FROM workforce_capacity_snapshots WHERE id = NEW.id) IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity snapshot is not sealed';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER workforce_capacity_capture_request_update_guard
BEFORE UPDATE OR DELETE ON workforce_capacity_capture_requests
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_capture_request_guard();

CREATE TRIGGER workforce_capacity_snapshot_insert_lineage
BEFORE INSERT ON workforce_capacity_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_snapshot_insert_guard();

CREATE TRIGGER workforce_capacity_item_insert_lineage
BEFORE INSERT ON workforce_capacity_snapshot_items
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_item_insert_guard();

CREATE TRIGGER workforce_capacity_snapshot_finalize
BEFORE UPDATE ON workforce_capacity_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_snapshot_finalize_guard();

CREATE CONSTRAINT TRIGGER workforce_capacity_snapshot_complete
AFTER INSERT ON workforce_capacity_snapshots
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_snapshot_commit_guard();

CREATE TRIGGER workforce_capacity_snapshot_delete_guard
BEFORE DELETE ON workforce_capacity_snapshots
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_prevent_mutation();

CREATE TRIGGER workforce_capacity_item_mutation_guard
BEFORE UPDATE OR DELETE ON workforce_capacity_snapshot_items
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_prevent_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS workforce_capacity_item_mutation_guard ON workforce_capacity_snapshot_items;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_delete_guard ON workforce_capacity_snapshots;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_complete ON workforce_capacity_snapshots;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_finalize ON workforce_capacity_snapshots;
DROP TRIGGER IF EXISTS workforce_capacity_item_insert_lineage ON workforce_capacity_snapshot_items;
DROP TRIGGER IF EXISTS workforce_capacity_snapshot_insert_lineage ON workforce_capacity_snapshots;
DROP TRIGGER IF EXISTS workforce_capacity_capture_request_update_guard ON workforce_capacity_capture_requests;
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_commit_guard();
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_finalize_guard();
DROP FUNCTION IF EXISTS workforce_capacity_item_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_capture_request_guard();
DROP FUNCTION IF EXISTS workforce_capacity_prevent_mutation();
DROP FUNCTION IF EXISTS workforce_capacity_json_has_forbidden(jsonb);
SQL);
        }

        Schema::dropIfExists('workforce_capacity_snapshot_items');
        Schema::dropIfExists('workforce_capacity_snapshots');
        Schema::dropIfExists('workforce_capacity_capture_requests');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('workforce_timezone');
        });
    }
};
