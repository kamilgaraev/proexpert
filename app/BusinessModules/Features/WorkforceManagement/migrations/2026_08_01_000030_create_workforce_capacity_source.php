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
            $table->string('cohort_cursor', 120)->nullable();
            $table->unsignedBigInteger('snapshot_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->jsonb('command_payload')->nullable();
            $table->text('command_canonical')->nullable();
            $table->char('command_hash', 64)->nullable();
            $table->jsonb('policy_definition')->nullable();
            $table->text('policy_canonical')->nullable();
            $table->char('policy_hash', 64)->nullable();
            $table->string('source_schema_version', 80)->nullable();
            $table->string('formula_version', 80)->nullable();
            $table->date('business_date')->nullable();
            $table->timestampTz('captured_at', 6)->nullable();
            $table->timestampTz('frozen_at', 6)->nullable();
            $table->unsignedBigInteger('range_count')->default(0);
            $table->unsignedBigInteger('source_row_count')->default(0);
            $table->timestampTz('available_at', 6)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_at', 6)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('started_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('dead_lettered_at', 6)->nullable();
            $table->unique(['organization_id', 'mutation_id'], 'workforce_capacity_capture_request_unique');
            $table->index(['status', 'available_at', 'id'], 'workforce_capacity_capture_recovery_idx');
        });

        Schema::create('workforce_capacity_capture_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('capture_request_id')
                ->constrained('workforce_capacity_capture_requests')
                ->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('staff_unit_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('from_month');
            $table->date('through_month');
            $table->timestampTz('created_at', 6);
            $table->index(
                ['capture_request_id', 'from_month', 'staff_unit_id', 'project_id', 'through_month'],
                'workforce_capacity_capture_range_scan_idx',
            );
        });

        Schema::create('workforce_capacity_frozen_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('capture_request_id')
                ->constrained('workforce_capacity_capture_requests')
                ->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('source_key', 160);
            $table->unsignedBigInteger('staff_unit_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->date('work_date')->nullable();
            $table->jsonb('payload');
            $table->text('payload_canonical');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at', 6);
            $table->unique(
                ['capture_request_id', 'source_type', 'source_key'],
                'workforce_capacity_frozen_source_unique',
            );
            $table->index(
                ['capture_request_id', 'staff_unit_id', 'project_id', 'valid_from', 'valid_to'],
                'workforce_capacity_frozen_staff_scope_idx',
            );
            $table->index(
                ['capture_request_id', 'employee_id', 'valid_from', 'valid_to'],
                'workforce_capacity_frozen_employee_scope_idx',
            );
            $table->index(
                ['capture_request_id', 'schedule_id', 'work_date'],
                'workforce_capacity_frozen_schedule_scope_idx',
            );
            $table->index(
                ['capture_request_id', 'source_type', 'source_id'],
                'workforce_capacity_frozen_type_source_idx',
            );
        });

        Schema::create('workforce_capacity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('as_of_date');
            $table->date('month_start');
            $table->unsignedBigInteger('staff_unit_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('capture_kind', 30);
            $table->foreignId('capture_request_id')
                ->nullable()
                ->constrained('workforce_capacity_capture_requests')
                ->restrictOnDelete();
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
            $table->unsignedBigInteger('staff_unit_id');
            $table->unsignedBigInteger('project_id')->nullable();
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
            $table->unsignedBigInteger('sealed_employee_id')->nullable();
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
        status IN ('preparing', 'pending', 'processing', 'completed', 'dead_lettered')
        AND snapshot_count >= 0
        AND chunk_count >= 0
        AND attempt_count >= 0
        AND range_count >= 0
        AND source_row_count >= 0
        AND (current_cursor IS NULL OR current_cursor ~ '^[0-9a-f]{64}$')
        AND (command_hash IS NULL OR command_hash ~ '^[0-9a-f]{64}$')
        AND (policy_hash IS NULL OR policy_hash ~ '^[0-9a-f]{64}$')
        AND ((command_payload IS NULL AND command_canonical IS NULL AND command_hash IS NULL
              AND policy_definition IS NULL AND policy_canonical IS NULL AND policy_hash IS NULL
              AND source_schema_version IS NULL AND formula_version IS NULL
              AND business_date IS NULL AND captured_at IS NULL AND frozen_at IS NULL
              AND range_count = 0 AND source_row_count = 0)
             OR (command_payload IS NOT NULL AND command_canonical IS NOT NULL AND command_hash IS NOT NULL
                 AND policy_definition IS NOT NULL AND policy_canonical IS NOT NULL AND policy_hash IS NOT NULL
                 AND source_schema_version = 'workforce-capacity-source.v1'
                 AND formula_version = 'workforce-capacity-formula.v1'
                 AND business_date IS NOT NULL AND captured_at IS NOT NULL
                 AND ((status = 'preparing' AND frozen_at IS NULL)
                      OR (status <> 'preparing' AND frozen_at IS NOT NULL
                          AND frozen_at >= captured_at
                          AND (range_count > 0
                               OR (status = 'completed' AND range_count = 0 AND source_row_count = 0))))))
        AND (available_at IS NULL OR frozen_at IS NULL OR available_at >= frozen_at)
        AND (claimed_at IS NULL OR claimed_at >= COALESCE(available_at, frozen_at, started_at))
        AND ((status = 'preparing' AND completed_at IS NULL AND dead_lettered_at IS NULL
              AND available_at IS NULL AND claim_token IS NULL AND claimed_at IS NULL)
             OR (status = 'processing' AND completed_at IS NULL AND dead_lettered_at IS NULL
              AND ((claim_token IS NULL AND claimed_at IS NULL) OR (claim_token IS NOT NULL AND claimed_at IS NOT NULL)))
             OR (status = 'pending' AND completed_at IS NULL AND dead_lettered_at IS NULL
                 AND available_at IS NOT NULL AND claim_token IS NULL AND claimed_at IS NULL)
             OR (status = 'completed' AND completed_at IS NOT NULL
                 AND completed_at >= COALESCE(captured_at, started_at)
                 AND dead_lettered_at IS NULL AND claim_token IS NULL AND claimed_at IS NULL)
             OR (status = 'dead_lettered' AND completed_at IS NULL AND dead_lettered_at IS NOT NULL
                 AND dead_lettered_at >= COALESCE(captured_at, started_at)
                 AND claim_token IS NULL AND claimed_at IS NULL))
    );

ALTER TABLE workforce_capacity_capture_ranges
    ADD CONSTRAINT workforce_capacity_capture_range_shape_check
    CHECK (
        organization_id > 0
        AND staff_unit_id > 0
        AND (project_id IS NULL OR project_id > 0)
        AND date_trunc('month', from_month)::date = from_month
        AND date_trunc('month', through_month)::date = through_month
        AND through_month >= from_month
    );

CREATE UNIQUE INDEX workforce_capacity_capture_range_unique
    ON workforce_capacity_capture_ranges (
        capture_request_id,
        staff_unit_id,
        COALESCE(project_id, 0),
        from_month,
        through_month
    );

ALTER TABLE workforce_capacity_frozen_source_rows
    ADD CONSTRAINT workforce_capacity_frozen_source_shape_check
    CHECK (
        organization_id > 0
        AND source_type IN (
            'staff_unit', 'assignment', 'employee_lifecycle', 'schedule',
            'schedule_day', 'absence', 'business_trip'
        )
        AND source_id > 0
        AND length(source_key) > 0
        AND (staff_unit_id IS NULL OR staff_unit_id > 0)
        AND (project_id IS NULL OR project_id > 0)
        AND (employee_id IS NULL OR employee_id > 0)
        AND (schedule_id IS NULL OR schedule_id > 0)
        AND (valid_to IS NULL OR (valid_from IS NOT NULL AND valid_to >= valid_from))
        AND jsonb_typeof(payload) = 'object'
        AND payload_hash ~ '^[0-9a-f]{64}$'
        AND ((source_type = 'staff_unit' AND staff_unit_id IS NOT NULL)
             OR (source_type = 'assignment' AND staff_unit_id IS NOT NULL
                 AND employee_id IS NOT NULL AND valid_from IS NOT NULL)
             OR (source_type = 'schedule' AND schedule_id IS NOT NULL)
             OR (source_type = 'schedule_day' AND schedule_id IS NOT NULL AND work_date IS NOT NULL)
             OR (source_type IN ('absence', 'business_trip')
                 AND employee_id IS NOT NULL AND valid_from IS NOT NULL AND valid_to IS NOT NULL)
             OR (source_type = 'employee_lifecycle' AND employee_id IS NOT NULL))
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

CREATE UNIQUE INDEX workforce_capacity_snapshot_request_idem_unique
    ON workforce_capacity_snapshots (
        capture_request_id,
        organization_id,
        as_of_date,
        month_start,
        staff_unit_id,
        COALESCE(project_id, 0),
        capture_kind,
        source_hash
    ) WHERE capture_request_id IS NOT NULL;

CREATE UNIQUE INDEX workforce_capacity_snapshot_live_idem_unique
    ON workforce_capacity_snapshots (
        organization_id,
        as_of_date,
        month_start,
        staff_unit_id,
        COALESCE(project_id, 0),
        capture_kind,
        source_hash
    ) WHERE capture_request_id IS NULL;

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

CREATE FUNCTION workforce_capacity_expected_policy(timezone_value text) RETURNS jsonb AS $$
    SELECT jsonb_build_object(
        'version', 'workforce-capacity-policy.v1',
        'timezone', timezone_value,
        'effective_date_semantics', 'inclusive',
        'staff_unit_rule', 'active_effective_not_deleted',
        'assignment_statuses', '["active"]'::jsonb,
        'unavailability_statuses', '["approved"]'::jsonb,
        'absence_type_rule', 'affects_payroll_true_v1',
        'project_attribution_rule', 'exact_or_null_bucket_no_derived_split',
        'calendar_precedence', '["schedule_day","weekly_pattern","gap"]'::jsonb,
        'calendar_non_work_day_types', '["day_off","holiday","non_work","weekend"]'::jsonb,
        'weekly_pattern_keys', '["1","2","3","4","5","6","7"]'::jsonb,
        'weekly_pattern_shapes', '["weekday_hours_map","work_days_with_explicit_hours_per_day"]'::jsonb,
        'weekly_pattern_hours_rule', 'explicit_only_no_default',
        'missing_schedule_rule', 'gap',
        'rounding', jsonb_build_object(
            'fte_scale', 4,
            'hours_scale', 2,
            'mode', 'half_up_at_render_boundary'
        ),
        'formula_order', '["authorized_fte","assigned_fte","approved_unavailability_fte","available_fte","open_fte","overallocated_fte","scheduled_hours","capacity_status"]'::jsonb,
        'status_precedence', '["gap","overallocated","unavailable","understaffed","balanced"]'::jsonb,
        'source_item_order', '["staff_unit","assignment","employee_lifecycle","schedule","schedule_day","absence","business_trip","capacity_gap"]'::jsonb,
        'capture_kinds', '["change_capture","scheduled_close","manual_recompute"]'::jsonb,
        'gap_codes', '["ambiguous_attribution","capture_gap","cross_scope_unavailability","inactive_schedule","inactive_staff_unit","invalid_schedule","missing_schedule","source_contract_missing"]'::jsonb,
        'redacted_fields', '["address","actual_hours","base_salary","comment","destination","email","first_name","last_name","middle_name","overtime","payroll_amount","personnel_number","phone","purpose","qr_payload","salary","salary_amount"]'::jsonb
    );
$$ LANGUAGE sql IMMUTABLE;

CREATE FUNCTION workforce_capacity_prevent_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity evidence is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_capture_range_insert_guard() RETURNS trigger AS $$
DECLARE
    request workforce_capacity_capture_requests%ROWTYPE;
BEGIN
    SELECT * INTO request
      FROM workforce_capacity_capture_requests
     WHERE id = NEW.capture_request_id;
    IF NOT FOUND
       OR request.status <> 'preparing'
       OR request.organization_id <> NEW.organization_id
       OR request.captured_at IS DISTINCT FROM NEW.created_at
       OR NOT EXISTS (
            SELECT 1
              FROM workforce_staff_units AS unit
             WHERE unit.id = NEW.staff_unit_id
               AND unit.organization_id = NEW.organization_id
       )
       OR (NEW.project_id IS NOT NULL AND NOT EXISTS (
            SELECT 1
              FROM projects AS project
             WHERE project.id = NEW.project_id
               AND project.organization_id = NEW.organization_id
       )) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen range invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_frozen_source_insert_guard() RETURNS trigger AS $$
DECLARE
    request workforce_capacity_capture_requests%ROWTYPE;
BEGIN
    SELECT * INTO request
      FROM workforce_capacity_capture_requests
     WHERE id = NEW.capture_request_id;
    IF NOT FOUND
       OR request.status <> 'preparing'
       OR request.organization_id <> NEW.organization_id
       OR request.captured_at IS DISTINCT FROM NEW.created_at
       OR NEW.payload IS DISTINCT FROM NEW.payload_canonical::jsonb
       OR encode(sha256(convert_to(NEW.payload_canonical, 'UTF8')), 'hex') <> NEW.payload_hash
       OR workforce_capacity_json_has_forbidden(NEW.payload)
       OR NULLIF(NEW.payload->>'id', '')::bigint IS DISTINCT FROM NEW.source_id THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen source invalid';
    END IF;

    IF (NEW.source_type = 'staff_unit' AND (
            NEW.source_id IS DISTINCT FROM NEW.staff_unit_id
            OR NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
        ))
       OR (NEW.source_type = 'assignment' AND (
            NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
            OR NULLIF(NEW.payload->>'staff_unit_id', '')::bigint IS DISTINCT FROM NEW.staff_unit_id
            OR NULLIF(NEW.payload->>'project_id', '')::bigint IS DISTINCT FROM NEW.project_id
            OR NULLIF(NEW.payload->>'employee_id', '')::bigint IS DISTINCT FROM NEW.employee_id
            OR NULLIF(NEW.payload->>'work_schedule_id', '')::bigint IS DISTINCT FROM NEW.schedule_id
            OR NULLIF(NEW.payload->>'valid_from', '')::date IS DISTINCT FROM NEW.valid_from
            OR NULLIF(NEW.payload->>'valid_to', '')::date IS DISTINCT FROM NEW.valid_to
        ))
       OR (NEW.source_type = 'schedule' AND (
            NEW.source_id IS DISTINCT FROM NEW.schedule_id
            OR NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
        ))
       OR (NEW.source_type = 'schedule_day' AND (
            NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
            OR NULLIF(NEW.payload->>'work_schedule_id', '')::bigint IS DISTINCT FROM NEW.schedule_id
            OR NULLIF(NEW.payload->>'work_date', '')::date IS DISTINCT FROM NEW.work_date
        ))
       OR (NEW.source_type IN ('absence', 'business_trip') AND (
            NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
            OR NULLIF(NEW.payload->>'employee_id', '')::bigint IS DISTINCT FROM NEW.employee_id
            OR NULLIF(NEW.payload->>'start_date', '')::date IS DISTINCT FROM NEW.valid_from
            OR NULLIF(NEW.payload->>'end_date', '')::date IS DISTINCT FROM NEW.valid_to
        ))
       OR (NEW.source_type = 'employee_lifecycle' AND (
            NEW.source_id IS DISTINCT FROM NEW.employee_id
            OR NULLIF(NEW.payload->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
        )) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen source routing invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_capture_request_guard() RETURNS trigger AS $$
DECLARE
    organization_timezone text;
    actual_range_count bigint;
    actual_source_row_count bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity capture is immutable';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.status = 'processing' THEN
            IF NEW.command_payload IS NOT NULL
               OR NEW.command_canonical IS NOT NULL
               OR NEW.command_hash IS NOT NULL
               OR NEW.policy_definition IS NOT NULL
               OR NEW.policy_canonical IS NOT NULL
               OR NEW.policy_hash IS NOT NULL
               OR NEW.source_schema_version IS NOT NULL
               OR NEW.formula_version IS NOT NULL
               OR NEW.business_date IS NOT NULL
               OR NEW.captured_at IS NOT NULL
               OR NEW.frozen_at IS NOT NULL
               OR NEW.current_cursor IS NOT NULL
               OR NEW.cohort_cursor IS NOT NULL
               OR NEW.snapshot_count <> 0
               OR NEW.chunk_count <> 0
               OR NEW.attempt_count <> 0
               OR NEW.range_count <> 0
               OR NEW.source_row_count <> 0
               OR NEW.available_at IS NOT NULL
               OR NEW.claim_token IS NOT NULL
               OR NEW.claimed_at IS NOT NULL
               OR NEW.last_error_code IS NOT NULL
               OR NEW.completed_at IS NOT NULL
               OR NEW.dead_lettered_at IS NOT NULL THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity synchronous capture invalid';
            END IF;

            RETURN NEW;
        END IF;

        SELECT workforce_timezone INTO organization_timezone
          FROM organizations
         WHERE id = NEW.organization_id;
        IF NEW.status <> 'preparing'
           OR NOT FOUND
           OR organization_timezone IS NULL
           OR NEW.command_payload IS DISTINCT FROM NEW.command_canonical::jsonb
           OR encode(sha256(convert_to(NEW.command_canonical, 'UTF8')), 'hex') <> NEW.command_hash
           OR jsonb_typeof(NEW.command_payload) <> 'object'
           OR (NEW.command_payload - ARRAY[
                'mutation_id', 'organization_id', 'source_type', 'old_state', 'new_state',
                'capture_kind', 'actor_user_id', 'service_actor',
                'source_schema_version', 'formula_version'
           ]::text[]) <> '{}'::jsonb
           OR NEW.command_payload->>'mutation_id' <> NEW.mutation_id
           OR (NEW.command_payload->>'organization_id')::bigint <> NEW.organization_id
           OR NEW.command_payload->>'source_type' NOT IN (
                'assignment', 'staff_unit', 'employee_lifecycle', 'schedule',
                'schedule_day', 'absence', 'business_trip', 'capture_request'
           )
           OR NEW.command_payload->>'capture_kind' NOT IN (
                'change_capture', 'scheduled_close', 'manual_recompute'
           )
           OR NEW.command_payload->>'source_schema_version' <> NEW.source_schema_version
           OR NEW.command_payload->>'formula_version' <> NEW.formula_version
           OR jsonb_typeof(NEW.command_payload->'old_state') NOT IN ('object', 'null')
           OR jsonb_typeof(NEW.command_payload->'new_state') NOT IN ('object', 'null')
           OR NEW.policy_definition IS DISTINCT FROM NEW.policy_canonical::jsonb
           OR NEW.policy_definition IS DISTINCT FROM workforce_capacity_expected_policy(organization_timezone)
           OR encode(sha256(convert_to(NEW.policy_canonical, 'UTF8')), 'hex') <> NEW.policy_hash
           OR workforce_capacity_json_has_forbidden(NEW.command_payload)
           OR workforce_capacity_json_has_forbidden(NEW.policy_definition - 'redacted_fields')
           OR NEW.snapshot_count <> 0
           OR NEW.chunk_count <> 0
           OR NEW.attempt_count <> 0
           OR NEW.range_count <> 0
           OR NEW.source_row_count <> 0 THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity deferred request invalid';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.status IN ('completed', 'dead_lettered')
       OR NEW.organization_id <> OLD.organization_id
       OR NEW.mutation_id <> OLD.mutation_id
       OR NEW.started_at <> OLD.started_at
       OR NEW.command_payload IS DISTINCT FROM OLD.command_payload
       OR NEW.command_canonical IS DISTINCT FROM OLD.command_canonical
       OR NEW.command_hash IS DISTINCT FROM OLD.command_hash
       OR NEW.policy_definition IS DISTINCT FROM OLD.policy_definition
       OR NEW.policy_canonical IS DISTINCT FROM OLD.policy_canonical
       OR NEW.policy_hash IS DISTINCT FROM OLD.policy_hash
       OR NEW.source_schema_version IS DISTINCT FROM OLD.source_schema_version
       OR NEW.formula_version IS DISTINCT FROM OLD.formula_version
       OR NEW.business_date IS DISTINCT FROM OLD.business_date
       OR NEW.captured_at IS DISTINCT FROM OLD.captured_at THEN
        RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity capture pins are immutable';
    END IF;

    IF OLD.status = 'preparing' THEN
        SELECT COUNT(*) INTO actual_range_count
          FROM workforce_capacity_capture_ranges
         WHERE capture_request_id = OLD.id;
        SELECT COUNT(*) INTO actual_source_row_count
          FROM workforce_capacity_frozen_source_rows
         WHERE capture_request_id = OLD.id;
        IF NEW.frozen_at IS NULL
           OR NEW.frozen_at < NEW.captured_at
           OR NEW.range_count <> actual_range_count
           OR NEW.source_row_count <> actual_source_row_count
           OR NEW.snapshot_count <> 0
           OR NEW.chunk_count <> 0
           OR NEW.attempt_count <> 0
           OR NEW.current_cursor IS NOT NULL
           OR NEW.cohort_cursor IS NOT NULL
           OR NEW.claim_token IS NOT NULL
           OR NEW.claimed_at IS NOT NULL
           OR NEW.last_error_code IS NOT NULL
           OR NEW.dead_lettered_at IS NOT NULL
           OR NOT (
                (NEW.status = 'pending'
                 AND NEW.range_count > 0
                 AND NEW.available_at IS NOT NULL
                 AND NEW.available_at >= NEW.frozen_at
                 AND NEW.completed_at IS NULL)
                OR (NEW.status = 'completed'
                    AND NEW.range_count = 0
                    AND NEW.source_row_count = 0
                    AND NEW.available_at IS NULL
                    AND NEW.completed_at >= NEW.frozen_at)
           ) THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity freeze seal invalid';
        END IF;

        RETURN NEW;
    END IF;

    IF NEW.frozen_at IS DISTINCT FROM OLD.frozen_at
       OR NEW.range_count <> OLD.range_count
       OR NEW.source_row_count <> OLD.source_row_count THEN
        RAISE EXCEPTION USING ERRCODE = '55000', MESSAGE = 'workforce capacity frozen source is immutable';
    END IF;

    IF NOT (
        (OLD.status = 'pending'
         AND NEW.status = 'processing'
         AND NEW.claim_token IS NOT NULL
         AND NEW.claimed_at IS NOT NULL
         AND NEW.attempt_count = OLD.attempt_count + 1
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count
         AND NEW.last_error_code IS NOT DISTINCT FROM OLD.last_error_code)
        OR
        (OLD.status = 'processing'
         AND OLD.claim_token IS NOT NULL
         AND NEW.status = 'processing'
         AND NEW.claim_token IS NOT NULL
         AND NEW.claim_token <> OLD.claim_token
         AND NEW.claimed_at > OLD.claimed_at
         AND NEW.attempt_count = OLD.attempt_count + 1
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count)
        OR
        (OLD.status = 'processing'
         AND NEW.status = 'processing'
         AND NEW.claim_token IS NOT DISTINCT FROM OLD.claim_token
         AND NEW.claimed_at IS NOT DISTINCT FROM OLD.claimed_at
         AND NEW.attempt_count = OLD.attempt_count
         AND NEW.current_cursor IS NOT NULL
         AND NEW.current_cursor IS DISTINCT FROM OLD.current_cursor
         AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor
         AND NEW.snapshot_count > OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count + 1)
        OR
        (OLD.status = 'processing'
         AND OLD.claim_token IS NOT NULL
         AND NEW.status = 'processing'
         AND NEW.claim_token IS NULL
         AND NEW.claimed_at IS NULL
         AND NEW.attempt_count = 0
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count
         AND NEW.cohort_cursor IS NOT NULL
         AND (OLD.cohort_cursor IS NULL OR NEW.cohort_cursor > OLD.cohort_cursor)
         AND NEW.last_error_code IS NULL)
        OR
        (OLD.status = 'processing'
         AND OLD.claim_token IS NOT NULL
         AND NEW.status = 'pending'
         AND NEW.claim_token IS NULL
         AND NEW.claimed_at IS NULL
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count
         AND ((NEW.last_error_code IS NULL
               AND NEW.attempt_count = 0
               AND NEW.cohort_cursor IS NOT NULL
               AND (OLD.cohort_cursor IS NULL OR NEW.cohort_cursor > OLD.cohort_cursor))
              OR (NEW.last_error_code ~ '^[a-z0-9_]{1,80}$'
                  AND NEW.attempt_count = OLD.attempt_count
                  AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor)))
        OR
        (OLD.status = 'processing'
         AND OLD.claim_token IS NULL
         AND NEW.status = 'completed'
         AND NEW.claim_token IS NULL
         AND NEW.claimed_at IS NULL
         AND NEW.attempt_count = OLD.attempt_count
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count
         AND NEW.completed_at IS NOT NULL)
        OR
        (OLD.status = 'processing'
         AND OLD.claim_token IS NOT NULL
         AND NEW.status = 'dead_lettered'
         AND NEW.claim_token IS NULL
         AND NEW.claimed_at IS NULL
         AND NEW.attempt_count = OLD.attempt_count
         AND NEW.current_cursor IS NOT DISTINCT FROM OLD.current_cursor
         AND NEW.cohort_cursor IS NOT DISTINCT FROM OLD.cohort_cursor
         AND NEW.snapshot_count = OLD.snapshot_count
         AND NEW.chunk_count = OLD.chunk_count
         AND NEW.last_error_code ~ '^[a-z0-9_]{1,80}$'
         AND NEW.dead_lettered_at IS NOT NULL)
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity capture transition invalid';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION workforce_capacity_snapshot_insert_guard() RETURNS trigger AS $$
DECLARE
    organization_timezone text;
    expected_policy jsonb;
    expected_state jsonb;
    expected_source jsonb;
    request workforce_capacity_capture_requests%ROWTYPE;
BEGIN
    IF NEW.policy_definition IS DISTINCT FROM NEW.policy_canonical::jsonb
       OR encode(sha256(convert_to(NEW.policy_canonical, 'UTF8')), 'hex') <> NEW.policy_hash
       OR encode(sha256(convert_to(NEW.items_canonical, 'UTF8')), 'hex') <> NEW.items_hash
       OR encode(sha256(convert_to(NEW.state_canonical, 'UTF8')), 'hex') <> NEW.state_hash
       OR encode(sha256(convert_to(NEW.source_canonical, 'UTF8')), 'hex') <> NEW.source_hash
       OR NEW.policy_definition->>'version' <> NEW.policy_version
       OR workforce_capacity_json_has_forbidden(NEW.policy_definition - 'redacted_fields') THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity policy or hash invalid';
    END IF;

    SELECT * INTO request
      FROM workforce_capacity_capture_requests AS capture
     WHERE capture.id = NEW.capture_request_id
        OR (NEW.capture_request_id IS NULL
            AND capture.organization_id = NEW.organization_id
            AND capture.mutation_id = NEW.capture_mutation_id)
     ORDER BY CASE WHEN capture.id = NEW.capture_request_id THEN 0 ELSE 1 END
     LIMIT 1;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity capture lineage invalid';
    END IF;
    NEW.capture_request_id := request.id;

    IF request.frozen_at IS NOT NULL THEN
        IF request.organization_id <> NEW.organization_id
           OR request.mutation_id <> NEW.capture_mutation_id
           OR request.source_schema_version <> NEW.source_schema_version
           OR request.formula_version <> NEW.formula_version
           OR request.policy_definition IS DISTINCT FROM NEW.policy_definition
           OR request.policy_canonical IS DISTINCT FROM NEW.policy_canonical
           OR request.policy_hash <> NEW.policy_hash
           OR request.captured_at IS DISTINCT FROM NEW.captured_at
           OR NOT EXISTS (
                SELECT 1
                  FROM workforce_capacity_capture_ranges AS capture_range
                 WHERE capture_range.capture_request_id = request.id
                   AND capture_range.organization_id = NEW.organization_id
                   AND capture_range.staff_unit_id = NEW.staff_unit_id
                   AND capture_range.project_id IS NOT DISTINCT FROM NEW.project_id
                   AND NEW.month_start BETWEEN capture_range.from_month AND capture_range.through_month
           ) THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen header lineage invalid';
        END IF;
    ELSE
        SELECT workforce_timezone INTO organization_timezone
          FROM organizations
         WHERE id = NEW.organization_id;
        IF NOT FOUND OR organization_timezone IS NULL THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity policy or hash invalid';
        END IF;
        expected_policy := workforce_capacity_expected_policy(organization_timezone);
        IF NEW.policy_definition IS DISTINCT FROM expected_policy THEN
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

    IF NEW.approved_unavailability_fte > NEW.assigned_fte
       OR NEW.available_fte <> GREATEST(NEW.assigned_fte - NEW.approved_unavailability_fte, 0)
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
    request workforce_capacity_capture_requests%ROWTYPE;
    content jsonb;
BEGIN
    SELECT * INTO snapshot FROM workforce_capacity_snapshots WHERE id = NEW.workforce_capacity_snapshot_id;
    IF FOUND THEN
        SELECT * INTO request
          FROM workforce_capacity_capture_requests
         WHERE id = snapshot.capture_request_id;
    END IF;
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
       OR jsonb_typeof(NEW.lineage) <> 'object'
       OR NULLIF(NEW.lineage->>'organization_id', '')::bigint IS DISTINCT FROM NEW.organization_id
       OR NULLIF(NEW.lineage->>'staff_unit_id', '')::bigint IS DISTINCT FROM NEW.staff_unit_id
       OR NULLIF(NEW.lineage->>'project_id', '')::bigint IS DISTINCT FROM NEW.project_id
       OR NULLIF(NEW.lineage->>'month_start', '')::date IS DISTINCT FROM NEW.month_start
       OR workforce_capacity_json_has_forbidden(NEW.source_canonical::jsonb)
       OR workforce_capacity_json_has_forbidden(NEW.content_canonical::jsonb) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity evidence payload invalid';
    END IF;

    IF request.frozen_at IS NOT NULL THEN
        IF NEW.source_type = 'capacity_gap' THEN
            IF NEW.source_id IS NOT NULL
               OR NEW.sealed_employee_id IS NOT NULL
               OR NOT (snapshot.gap_codes ? (NEW.evidence->>'gap_code')) THEN
                RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen gap lineage invalid';
            END IF;
        ELSIF NOT EXISTS (
            SELECT 1
              FROM workforce_capacity_frozen_source_rows AS frozen
             WHERE frozen.capture_request_id = request.id
               AND frozen.organization_id = NEW.organization_id
               AND frozen.source_type = NEW.source_type
               AND frozen.source_id IS NOT DISTINCT FROM NEW.source_id
               AND frozen.payload IS NOT DISTINCT FROM NEW.source_canonical::jsonb->'source'
               AND (
                    (NEW.source_type = 'staff_unit'
                     AND frozen.staff_unit_id = snapshot.staff_unit_id)
                    OR (NEW.source_type = 'assignment'
                        AND frozen.staff_unit_id = snapshot.staff_unit_id
                        AND frozen.project_id IS NOT DISTINCT FROM snapshot.project_id
                        AND frozen.employee_id = NEW.sealed_employee_id
                        AND frozen.valid_from <= snapshot.as_of_date
                        AND (frozen.valid_to IS NULL OR frozen.valid_to >= snapshot.as_of_date))
                    OR (NEW.source_type = 'schedule'
                        AND frozen.schedule_id = NEW.source_id
                        AND EXISTS (
                            SELECT 1
                              FROM workforce_capacity_snapshot_items AS assignment_item
                             WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                               AND assignment_item.source_type = 'assignment'
                               AND (assignment_item.evidence->>'work_schedule_id')::bigint = frozen.schedule_id
                        ))
                    OR (NEW.source_type = 'schedule_day'
                        AND date_trunc('month', frozen.work_date)::date = snapshot.month_start
                        AND EXISTS (
                            SELECT 1
                              FROM workforce_capacity_snapshot_items AS schedule_item
                             WHERE schedule_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                               AND schedule_item.source_type = 'schedule'
                               AND schedule_item.source_id = frozen.schedule_id
                        ))
                    OR (NEW.source_type = 'absence'
                        AND frozen.employee_id = NEW.sealed_employee_id
                        AND frozen.valid_from <= snapshot.as_of_date
                        AND frozen.valid_to >= snapshot.as_of_date
                        AND EXISTS (
                            SELECT 1
                              FROM workforce_capacity_snapshot_items AS assignment_item
                             WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                               AND assignment_item.source_type = 'assignment'
                               AND assignment_item.sealed_employee_id = frozen.employee_id
                        )
                        AND (snapshot.project_id IS NULL OR snapshot.gap_codes ? 'cross_scope_unavailability'))
                    OR (NEW.source_type = 'business_trip'
                        AND frozen.employee_id = NEW.sealed_employee_id
                        AND frozen.valid_from <= snapshot.as_of_date
                        AND frozen.valid_to >= snapshot.as_of_date
                        AND EXISTS (
                            SELECT 1
                              FROM workforce_capacity_snapshot_items AS assignment_item
                             WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                               AND assignment_item.source_type = 'assignment'
                               AND assignment_item.sealed_employee_id = frozen.employee_id
                        )
                        AND (frozen.project_id IS NOT DISTINCT FROM snapshot.project_id
                             OR snapshot.gap_codes ? 'cross_scope_unavailability'))
                    OR (NEW.source_type = 'employee_lifecycle'
                        AND frozen.employee_id = NEW.sealed_employee_id
                        AND EXISTS (
                            SELECT 1
                              FROM workforce_capacity_snapshot_items AS assignment_item
                             WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                               AND assignment_item.source_type = 'assignment'
                               AND assignment_item.sealed_employee_id = frozen.employee_id
                        ))
               )
        ) THEN
            RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity frozen evidence lineage invalid';
        END IF;

        RETURN NEW;
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
           AND EXISTS (
               SELECT 1 FROM workforce_capacity_snapshot_items AS assignment_item
                WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                  AND assignment_item.source_type = 'assignment'
                  AND (assignment_item.evidence->>'work_schedule_id')::bigint = schedule.id
           )
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity schedule lineage invalid';
    ELSIF NEW.source_type = 'schedule_day' AND NOT EXISTS (
        SELECT 1 FROM workforce_work_schedule_days AS schedule_day
         WHERE schedule_day.id = NEW.source_id AND schedule_day.organization_id = NEW.organization_id
           AND date_trunc('month', schedule_day.work_date)::date = NEW.month_start
           AND EXISTS (
               SELECT 1 FROM workforce_capacity_snapshot_items AS schedule_item
                WHERE schedule_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                  AND schedule_item.source_type = 'schedule'
                  AND schedule_item.source_id = schedule_day.work_schedule_id
           )
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity schedule day lineage invalid';
    ELSIF NEW.source_type = 'absence' AND NOT EXISTS (
        SELECT 1 FROM workforce_absences AS absence
         WHERE absence.id = NEW.source_id AND absence.organization_id = NEW.organization_id
           AND absence.employee_id = NEW.sealed_employee_id
           AND EXISTS (
               SELECT 1 FROM workforce_capacity_snapshot_items AS assignment_item
                WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                  AND assignment_item.source_type = 'assignment'
                  AND assignment_item.sealed_employee_id = absence.employee_id
           )
           AND (
               snapshot.project_id IS NULL
               OR snapshot.gap_codes ? 'cross_scope_unavailability'
           )
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity absence lineage invalid';
    ELSIF NEW.source_type = 'business_trip' AND NOT EXISTS (
        SELECT 1 FROM workforce_business_trips AS trip
         WHERE trip.id = NEW.source_id AND trip.organization_id = NEW.organization_id
           AND trip.employee_id = NEW.sealed_employee_id
           AND EXISTS (
               SELECT 1 FROM workforce_capacity_snapshot_items AS assignment_item
                WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                  AND assignment_item.source_type = 'assignment'
                  AND assignment_item.sealed_employee_id = trip.employee_id
           )
           AND (
               trip.project_id IS NOT DISTINCT FROM snapshot.project_id
               OR snapshot.gap_codes ? 'cross_scope_unavailability'
           )
    ) THEN
        RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'workforce capacity business trip lineage invalid';
    ELSIF NEW.source_type = 'employee_lifecycle' AND NOT EXISTS (
        SELECT 1 FROM workforce_employees AS employee
         WHERE employee.id = NEW.source_id AND employee.organization_id = NEW.organization_id
           AND employee.id = NEW.sealed_employee_id
           AND EXISTS (
               SELECT 1 FROM workforce_capacity_snapshot_items AS assignment_item
                WHERE assignment_item.workforce_capacity_snapshot_id = NEW.workforce_capacity_snapshot_id
                  AND assignment_item.source_type = 'assignment'
                  AND assignment_item.sealed_employee_id = employee.id
           )
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
BEFORE INSERT OR UPDATE OR DELETE ON workforce_capacity_capture_requests
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_capture_request_guard();

CREATE TRIGGER workforce_capacity_capture_range_insert_lineage
BEFORE INSERT ON workforce_capacity_capture_ranges
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_capture_range_insert_guard();

CREATE TRIGGER workforce_capacity_capture_range_mutation_guard
BEFORE UPDATE OR DELETE ON workforce_capacity_capture_ranges
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_prevent_mutation();

CREATE TRIGGER workforce_capacity_frozen_source_insert_lineage
BEFORE INSERT ON workforce_capacity_frozen_source_rows
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_frozen_source_insert_guard();

CREATE TRIGGER workforce_capacity_frozen_source_mutation_guard
BEFORE UPDATE OR DELETE ON workforce_capacity_frozen_source_rows
FOR EACH ROW EXECUTE FUNCTION workforce_capacity_prevent_mutation();

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
DROP TRIGGER IF EXISTS workforce_capacity_frozen_source_mutation_guard ON workforce_capacity_frozen_source_rows;
DROP TRIGGER IF EXISTS workforce_capacity_frozen_source_insert_lineage ON workforce_capacity_frozen_source_rows;
DROP TRIGGER IF EXISTS workforce_capacity_capture_range_mutation_guard ON workforce_capacity_capture_ranges;
DROP TRIGGER IF EXISTS workforce_capacity_capture_range_insert_lineage ON workforce_capacity_capture_ranges;
DROP TRIGGER IF EXISTS workforce_capacity_capture_request_update_guard ON workforce_capacity_capture_requests;
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_commit_guard();
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_finalize_guard();
DROP FUNCTION IF EXISTS workforce_capacity_item_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_snapshot_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_capture_request_guard();
DROP FUNCTION IF EXISTS workforce_capacity_frozen_source_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_capture_range_insert_guard();
DROP FUNCTION IF EXISTS workforce_capacity_prevent_mutation();
DROP FUNCTION IF EXISTS workforce_capacity_expected_policy(text);
DROP FUNCTION IF EXISTS workforce_capacity_json_has_forbidden(jsonb);
SQL);
        }

        Schema::dropIfExists('workforce_capacity_snapshot_items');
        Schema::dropIfExists('workforce_capacity_snapshots');
        Schema::dropIfExists('workforce_capacity_frozen_source_rows');
        Schema::dropIfExists('workforce_capacity_capture_ranges');
        Schema::dropIfExists('workforce_capacity_capture_requests');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('workforce_timezone');
        });
    }
};
