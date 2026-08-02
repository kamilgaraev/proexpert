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
        Schema::create('quality_defect_flow_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_code', 64);
            $table->unsignedInteger('version');
            $table->jsonb('canonical_policy');
            $table->char('policy_hash', 64);
            $table->timestampTz('created_at', 6);

            $table->unique(['policy_code', 'version'], 'quality_defect_flow_policy_version_unique');
            $table->unique('policy_hash', 'quality_defect_flow_policy_hash_unique');
        });

        Schema::create('quality_defect_flow_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->restrictOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained('contractors')->restrictOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->restrictOnDelete();
            $table->foreignId('acceptance_scope_id')->nullable()->constrained('acceptance_scopes')->restrictOnDelete();
            $table->foreignId('acceptance_session_id')->nullable()->constrained('acceptance_sessions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at_utc', 6);
            $table->unsignedInteger('sequence_no');
            $table->string('event_kind', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('terminal_reason', 48)->nullable();
            $table->foreignId('policy_id')->constrained('quality_defect_flow_policies')->restrictOnDelete();
            $table->unsignedInteger('policy_version');
            $table->char('policy_hash', 64);
            $table->jsonb('business_snapshot');
            $table->jsonb('source_identity');
            $table->char('source_identity_hash', 64);
            $table->char('source_hash', 64);
            $table->char('evidence_hash', 64);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['organization_id', 'quality_defect_id', 'sequence_no'],
                'quality_defect_flow_event_sequence_unique',
            );
            $table->unique(
                ['organization_id', 'quality_defect_id', 'event_kind', 'source_identity_hash'],
                'quality_defect_flow_event_identity_unique',
            );
            $table->index(
                ['organization_id', 'project_id', 'occurred_at_utc', 'event_id'],
                'quality_defect_flow_scope_timeline_idx',
            );
            $table->index(
                ['organization_id', 'quality_defect_id', 'sequence_no'],
                'quality_defect_flow_defect_sequence_idx',
            );
        });

        Schema::create('quality_defect_flow_gaps', function (Blueprint $table): void {
            $table->uuid('gap_id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->restrictOnDelete();
            $table->string('gap_code', 64);
            $table->timestampTz('detected_at_utc', 6);
            $table->foreignId('policy_id')->constrained('quality_defect_flow_policies')->restrictOnDelete();
            $table->unsignedInteger('policy_version');
            $table->char('policy_hash', 64);
            $table->jsonb('source_identity');
            $table->char('source_identity_hash', 64);
            $table->char('source_hash', 64);
            $table->char('evidence_hash', 64);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['organization_id', 'quality_defect_id', 'gap_code', 'source_identity_hash'],
                'quality_defect_flow_gap_identity_unique',
            );
            $table->index(
                ['organization_id', 'project_id', 'detected_at_utc', 'gap_id'],
                'quality_defect_flow_gap_scope_timeline_idx',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_canonical_json(value jsonb)
RETURNS text AS $$
DECLARE
    canonical text;
BEGIN
    IF jsonb_typeof(value) = 'object' THEN
        SELECT '{' || COALESCE(string_agg(
            to_jsonb(entry.key)::text || ':' || quality_defect_flow_canonical_json(entry.value),
            ',' ORDER BY entry.key
        ), '') || '}'
        INTO canonical
        FROM jsonb_each(value) AS entry;

        RETURN canonical;
    END IF;

    IF jsonb_typeof(value) = 'array' THEN
        SELECT '[' || COALESCE(string_agg(
            quality_defect_flow_canonical_json(entry.value),
            ',' ORDER BY entry.ordinality
        ), '') || ']'
        INTO canonical
        FROM jsonb_array_elements(value) WITH ORDINALITY AS entry(value, ordinality);

        RETURN canonical;
    END IF;

    RETURN value::text;
END;
$$ LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_hash_json(value jsonb)
RETURNS text AS $$
    SELECT encode(sha256(convert_to(quality_defect_flow_canonical_json(value), 'UTF8')), 'hex')
$$ LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE quality_defect_flow_policies
ADD CONSTRAINT quality_defect_flow_policy_contract_check
CHECK (
    policy_code = 'quality-defect-flow.v1'
    AND version = 1
    AND policy_hash ~ '^[a-f0-9]{64}$'
    AND policy_hash = quality_defect_flow_hash_json(canonical_policy)
    AND jsonb_typeof(canonical_policy) = 'object'
    AND canonical_policy->>'policy_code' = policy_code
    AND (canonical_policy->>'version')::integer = version
    AND canonical_policy->'terminal_reasons' @> '["cancelled_by_user"]'::jsonb
    AND canonical_policy->'reopen' @> '{"enabled":false,"count":0,"requires_new_policy":true}'::jsonb
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX quality_defect_flow_event_source_identity_unique
ON quality_defect_flow_events (
    organization_id,
    quality_defect_id,
    event_kind,
    source_identity
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX quality_defect_flow_gap_source_identity_unique
ON quality_defect_flow_gaps (
    organization_id,
    quality_defect_id,
    gap_code,
    source_identity
)
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE quality_defect_flow_events
ADD CONSTRAINT quality_defect_flow_event_hashes_check
CHECK (
    policy_hash ~ '^[a-f0-9]{64}$'
    AND source_identity_hash ~ '^[a-f0-9]{64}$'
    AND source_hash ~ '^[a-f0-9]{64}$'
    AND evidence_hash ~ '^[a-f0-9]{64}$'
),
ADD CONSTRAINT quality_defect_flow_event_kind_check
CHECK (event_kind IN (
    'created',
    'assigned',
    'started',
    'submitted_for_review',
    'verified_resolved',
    'returned_for_rework',
    'rejected',
    'cancelled'
)),
ADD CONSTRAINT quality_defect_flow_event_status_check
CHECK (
    (from_status IS NULL OR from_status IN (
        'draft', 'open', 'assigned', 'in_progress', 'ready_for_review', 'resolved', 'rejected', 'cancelled'
    ))
    AND to_status IN (
        'draft', 'open', 'assigned', 'in_progress', 'ready_for_review', 'resolved', 'rejected', 'cancelled'
    )
),
ADD CONSTRAINT quality_defect_flow_event_snapshot_check
CHECK (
    jsonb_typeof(business_snapshot) = 'object'
    AND business_snapshot ?& ARRAY[
        'schema_version', 'organization_id', 'project_id', 'quality_defect_id',
        'contractor_id', 'schedule_task_id', 'severity', 'due_date', 'has_due_date',
        'inspection_required', 'assignee_id', 'source_link'
    ]
    AND business_snapshot - ARRAY[
        'schema_version', 'organization_id', 'project_id', 'quality_defect_id',
        'contractor_id', 'schedule_task_id', 'severity', 'due_date', 'has_due_date',
        'inspection_required', 'assignee_id', 'source_link'
    ] = '{}'::jsonb
    AND business_snapshot->>'schema_version' = 'quality-defect-flow-snapshot.v1'
    AND business_snapshot->>'organization_id' = organization_id::text
    AND business_snapshot->>'project_id' = project_id::text
    AND business_snapshot->>'quality_defect_id' = quality_defect_id::text
    AND business_snapshot->>'contractor_id' IS NOT DISTINCT FROM contractor_id::text
    AND business_snapshot->>'schedule_task_id' IS NOT DISTINCT FROM schedule_task_id::text
    AND business_snapshot->>'assignee_id' IS NOT DISTINCT FROM assignee_id::text
    AND jsonb_typeof(business_snapshot->'inspection_required') = 'boolean'
    AND jsonb_typeof(business_snapshot->'has_due_date') = 'boolean'
    AND jsonb_typeof(business_snapshot->'source_link') = 'object'
),
ADD CONSTRAINT quality_defect_flow_event_source_identity_check
CHECK (
    jsonb_typeof(source_identity) = 'object'
    AND source_identity - ARRAY['kind', 'id'] = '{}'::jsonb
    AND source_identity->>'kind' = 'quality_defect_status_history'
    AND source_identity->>'id' ~ '^[1-9][0-9]*$'
),
ADD CONSTRAINT quality_defect_flow_event_sequence_check
CHECK (sequence_no > 0 AND policy_version = 1)
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE quality_defect_flow_gaps
ADD CONSTRAINT quality_defect_flow_gap_contract_check
CHECK (
    gap_code = 'source_contract_missing'
    AND project_id IS NULL
    AND policy_version = 1
    AND policy_hash ~ '^[a-f0-9]{64}$'
    AND source_identity_hash ~ '^[a-f0-9]{64}$'
    AND source_hash ~ '^[a-f0-9]{64}$'
    AND evidence_hash ~ '^[a-f0-9]{64}$'
    AND jsonb_typeof(source_identity) = 'object'
    AND source_identity - ARRAY['kind', 'id'] = '{}'::jsonb
    AND source_identity->>'kind' = 'quality_defect_status_history'
    AND source_identity->>'id' ~ '^[1-9][0-9]*$'
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_reject_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'quality defect flow source is append-only' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql
SQL);

        foreach (['quality_defect_flow_policies', 'quality_defect_flow_events', 'quality_defect_flow_gaps'] as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_reject_mutation "
                ."BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_reject_mutation()',
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_validate_policy_pin(
    p_policy_id bigint,
    p_policy_version integer,
    p_policy_hash text,
    p_terminal_reason text
) RETURNS void AS $$
DECLARE
    pinned quality_defect_flow_policies%ROWTYPE;
BEGIN
    SELECT * INTO pinned
    FROM quality_defect_flow_policies
    WHERE id = p_policy_id
    FOR KEY SHARE;

    IF NOT FOUND
       OR pinned.version <> p_policy_version
       OR pinned.policy_hash <> p_policy_hash THEN
        RAISE EXCEPTION 'quality defect flow policy pin mismatch' USING ERRCODE = '23514';
    END IF;

    IF p_terminal_reason IS NOT NULL
       AND NOT (pinned.canonical_policy->'terminal_reasons' @> to_jsonb(ARRAY[p_terminal_reason])) THEN
        RAISE EXCEPTION 'quality defect flow terminal reason is not allowed' USING ERRCODE = '23514';
    END IF;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_validate_user_membership(
    p_user_id bigint,
    p_organization_id bigint,
    p_project_id bigint
) RETURNS boolean AS $$
DECLARE
    access_mode text;
BEGIN
    IF p_user_id IS NULL THEN
        RETURN true;
    END IF;

    SELECT project_access_mode INTO access_mode
    FROM organization_user
    WHERE user_id = p_user_id
      AND organization_id = p_organization_id
      AND is_active = true;

    IF NOT FOUND THEN
        RETURN false;
    END IF;

    IF access_mode IS DISTINCT FROM 'restricted' THEN
        RETURN true;
    END IF;

    RETURN EXISTS (
        SELECT 1 FROM project_user
        WHERE user_id = p_user_id
          AND project_id = p_project_id
          AND is_active = true
    );
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_validate_event()
RETURNS trigger AS $$
DECLARE
    defect quality_defects%ROWTYPE;
    previous quality_defect_flow_events%ROWTYPE;
    source_link jsonb;
    expected_source_hash text;
    expected_evidence_hash text;
BEGIN
    SELECT * INTO defect
    FROM quality_defects
    WHERE id = NEW.quality_defect_id
    FOR UPDATE;

    IF NOT FOUND
       OR defect.organization_id <> NEW.organization_id
       OR defect.project_id <> NEW.project_id
       OR defect.contractor_id IS DISTINCT FROM NEW.contractor_id
       OR defect.schedule_task_id IS DISTINCT FROM NEW.schedule_task_id
       OR defect.assigned_to IS DISTINCT FROM NEW.assignee_id
       OR defect.status <> NEW.to_status
       OR defect.severity <> NEW.business_snapshot->>'severity'
       OR defect.due_date::text IS DISTINCT FROM NEW.business_snapshot->>'due_date'
       OR defect.inspection_required IS DISTINCT FROM (NEW.business_snapshot->>'inspection_required')::boolean
       OR (defect.due_date IS NOT NULL) IS DISTINCT FROM (NEW.business_snapshot->>'has_due_date')::boolean THEN
        RAISE EXCEPTION 'quality defect flow defect lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM projects
        WHERE id = NEW.project_id
          AND organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION 'quality defect flow project lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM quality_defect_status_history
        WHERE id::text = NEW.source_identity->>'id'
          AND quality_defect_id = NEW.quality_defect_id
          AND organization_id = NEW.organization_id
          AND from_status IS NOT DISTINCT FROM NEW.from_status
          AND to_status = NEW.to_status
          AND changed_by IS NOT DISTINCT FROM NEW.actor_id
          AND changed_at = NEW.occurred_at_utc AT TIME ZONE 'UTC'
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'quality defect flow owner history lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.contractor_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contractors
        WHERE id = NEW.contractor_id
          AND organization_id = NEW.organization_id
    ) THEN
        RAISE EXCEPTION 'quality defect flow contractor lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.schedule_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM schedule_tasks
        INNER JOIN project_schedules ON project_schedules.id = schedule_tasks.schedule_id
        WHERE schedule_tasks.id = NEW.schedule_task_id
          AND schedule_tasks.organization_id = NEW.organization_id
          AND project_schedules.organization_id = NEW.organization_id
          AND project_schedules.project_id = NEW.project_id
    ) THEN
        RAISE EXCEPTION 'quality defect flow schedule task lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT quality_defect_flow_validate_user_membership(NEW.actor_id, NEW.organization_id, NEW.project_id)
       OR NOT quality_defect_flow_validate_user_membership(NEW.assignee_id, NEW.organization_id, NEW.project_id) THEN
        RAISE EXCEPTION 'quality defect flow user lineage mismatch' USING ERRCODE = '23514';
    END IF;

    source_link := NEW.business_snapshot->'source_link';
    IF source_link->>'classification' IN ('quality_defect', 'work_constraint') THEN
        IF NEW.acceptance_scope_id IS NOT NULL
           OR NEW.acceptance_session_id IS NOT NULL
           OR source_link - ARRAY['classification'] <> '{}'::jsonb THEN
            RAISE EXCEPTION 'quality defect flow source link mismatch' USING ERRCODE = '23514';
        END IF;
    ELSIF source_link->>'classification' = 'acceptance_finding' THEN
        IF NEW.acceptance_scope_id IS NULL
           OR NEW.acceptance_session_id IS NULL
           OR source_link->>'acceptance_scope_id' <> NEW.acceptance_scope_id::text
           OR source_link->>'acceptance_session_id' <> NEW.acceptance_session_id::text
           OR source_link - ARRAY['classification', 'acceptance_scope_id', 'acceptance_session_id'] <> '{}'::jsonb
           OR NOT EXISTS (
                SELECT 1 FROM acceptance_scopes
                WHERE id = NEW.acceptance_scope_id
                  AND organization_id = NEW.organization_id
                  AND project_id = NEW.project_id
           )
           OR NOT EXISTS (
                SELECT 1 FROM acceptance_sessions
                WHERE id = NEW.acceptance_session_id
                  AND acceptance_scope_id = NEW.acceptance_scope_id
                  AND organization_id = NEW.organization_id
                  AND project_id = NEW.project_id
           ) THEN
            RAISE EXCEPTION 'quality defect flow acceptance lineage mismatch' USING ERRCODE = '23514';
        END IF;
    ELSE
        RAISE EXCEPTION 'quality defect flow source classification mismatch' USING ERRCODE = '23514';
    END IF;

    PERFORM quality_defect_flow_validate_policy_pin(
        NEW.policy_id,
        NEW.policy_version,
        NEW.policy_hash,
        NEW.terminal_reason
    );

    IF NEW.source_identity_hash <> quality_defect_flow_hash_json(NEW.source_identity) THEN
        RAISE EXCEPTION 'quality defect flow source identity hash mismatch' USING ERRCODE = '23514';
    END IF;

    expected_source_hash := quality_defect_flow_hash_json(jsonb_build_object(
        'actor_id', NEW.actor_id::text,
        'business_snapshot', NEW.business_snapshot,
        'event_kind', NEW.event_kind,
        'from_status', NEW.from_status,
        'occurred_at_utc', to_char(NEW.occurred_at_utc AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'source_identity', NEW.source_identity,
        'terminal_reason', NEW.terminal_reason,
        'to_status', NEW.to_status
    ));
    IF NEW.source_hash <> expected_source_hash THEN
        RAISE EXCEPTION 'quality defect flow source hash mismatch' USING ERRCODE = '23514';
    END IF;

    expected_evidence_hash := quality_defect_flow_hash_json(jsonb_build_object(
        'event_id', NEW.event_id::text,
        'event_kind', NEW.event_kind,
        'from_status', NEW.from_status,
        'occurred_at_utc', to_char(NEW.occurred_at_utc AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'policy_code', 'quality-defect-flow.v1',
        'policy_hash', NEW.policy_hash,
        'policy_version', NEW.policy_version,
        'sequence_no', NEW.sequence_no,
        'source_hash', NEW.source_hash,
        'terminal_reason', NEW.terminal_reason,
        'to_status', NEW.to_status
    ));
    IF NEW.evidence_hash <> expected_evidence_hash THEN
        RAISE EXCEPTION 'quality defect flow evidence hash mismatch' USING ERRCODE = '23514';
    END IF;

    SELECT * INTO previous
    FROM quality_defect_flow_events
    WHERE organization_id = NEW.organization_id
      AND quality_defect_id = NEW.quality_defect_id
    ORDER BY sequence_no DESC
    LIMIT 1;

    IF NOT FOUND THEN
        IF EXISTS (
            SELECT 1 FROM quality_defect_flow_gaps
            WHERE organization_id = NEW.organization_id
              AND quality_defect_id = NEW.quality_defect_id
        ) THEN
            RAISE EXCEPTION 'quality defect flow gap prohibits current-card reconstruction' USING ERRCODE = '23514';
        END IF;

        IF NEW.sequence_no <> 1
           OR NEW.event_kind <> 'created'
           OR NEW.from_status IS NOT NULL
           OR NEW.to_status NOT IN ('open', 'assigned') THEN
            RAISE EXCEPTION 'quality defect flow initial event required' USING ERRCODE = '23514';
        END IF;
    ELSE
        IF previous.business_snapshot->'source_link' IS DISTINCT FROM source_link THEN
            RAISE EXCEPTION 'quality defect flow source link cannot change after initial evidence' USING ERRCODE = '23514';
        END IF;

        IF NEW.event_kind = 'created'
           OR NEW.sequence_no <> previous.sequence_no + 1
           OR NEW.from_status IS DISTINCT FROM previous.to_status
           OR NEW.occurred_at_utc < previous.occurred_at_utc
           OR (NEW.occurred_at_utc = previous.occurred_at_utc AND NEW.event_id::text <= previous.event_id::text) THEN
            RAISE EXCEPTION 'quality defect flow sequence or time inversion' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NOT (
        (NEW.event_kind = 'created' AND NEW.from_status IS NULL AND NEW.to_status IN ('open', 'assigned'))
        OR (NEW.event_kind = 'assigned' AND NEW.from_status IN ('open', 'rejected') AND NEW.to_status = 'assigned')
        OR (NEW.event_kind = 'started' AND NEW.from_status IN ('open', 'assigned', 'rejected') AND NEW.to_status = 'in_progress')
        OR (NEW.event_kind = 'submitted_for_review' AND NEW.from_status IN ('open', 'assigned', 'in_progress', 'rejected') AND NEW.to_status = 'ready_for_review')
        OR (NEW.event_kind = 'verified_resolved' AND NEW.from_status = 'ready_for_review' AND NEW.to_status = 'resolved')
        OR (NEW.event_kind = 'returned_for_rework' AND NEW.from_status = 'ready_for_review' AND NEW.to_status = 'rejected')
        OR (NEW.event_kind = 'rejected' AND NEW.from_status IN ('draft', 'open', 'assigned', 'in_progress', 'ready_for_review', 'rejected') AND NEW.to_status = 'rejected')
        OR (NEW.event_kind = 'cancelled' AND NEW.from_status IN ('draft', 'open', 'assigned', 'in_progress', 'rejected') AND NEW.to_status = 'cancelled' AND NEW.terminal_reason = 'cancelled_by_user')
    ) THEN
        RAISE EXCEPTION 'quality defect flow transition is not allowed' USING ERRCODE = '23514';
    END IF;

    IF (NEW.event_kind = 'cancelled') <> (NEW.terminal_reason IS NOT NULL) THEN
        RAISE EXCEPTION 'quality defect flow terminal reason mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::unprepared(
            'CREATE TRIGGER quality_defect_flow_events_validate '
            .'BEFORE INSERT ON quality_defect_flow_events '
            .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_validate_event()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_validate_gap()
RETURNS trigger AS $$
DECLARE
    expected_source_hash text;
    expected_evidence_hash text;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM quality_defects
        WHERE id = NEW.quality_defect_id
          AND organization_id = NEW.organization_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'quality defect flow gap lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM quality_defect_status_history
        WHERE id::text = NEW.source_identity->>'id'
          AND quality_defect_id = NEW.quality_defect_id
          AND organization_id = NEW.organization_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'quality defect flow gap source identity mismatch' USING ERRCODE = '23514';
    END IF;

    PERFORM quality_defect_flow_validate_policy_pin(
        NEW.policy_id,
        NEW.policy_version,
        NEW.policy_hash,
        NULL
    );

    IF NEW.source_identity_hash <> quality_defect_flow_hash_json(NEW.source_identity) THEN
        RAISE EXCEPTION 'quality defect flow gap source identity hash mismatch' USING ERRCODE = '23514';
    END IF;

    expected_source_hash := quality_defect_flow_hash_json(jsonb_build_object(
        'gap_code', NEW.gap_code,
        'organization_id', NEW.organization_id::text,
        'policy_hash', NEW.policy_hash,
        'quality_defect_id', NEW.quality_defect_id::text,
        'source_identity', NEW.source_identity
    ));
    IF NEW.source_hash <> expected_source_hash THEN
        RAISE EXCEPTION 'quality defect flow gap source hash mismatch' USING ERRCODE = '23514';
    END IF;

    expected_evidence_hash := quality_defect_flow_hash_json(jsonb_build_object(
        'gap_id', NEW.gap_id::text,
        'policy_hash', NEW.policy_hash,
        'source_hash', NEW.source_hash
    ));
    IF NEW.evidence_hash <> expected_evidence_hash THEN
        RAISE EXCEPTION 'quality defect flow gap evidence hash mismatch' USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1 FROM quality_defect_flow_events
        WHERE organization_id = NEW.organization_id
          AND quality_defect_id = NEW.quality_defect_id
          AND sequence_no = 1
    ) THEN
        RAISE EXCEPTION 'quality defect flow gap cannot replace proven initial event' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::unprepared(
            'CREATE TRIGGER quality_defect_flow_gaps_validate '
            .'BEFORE INSERT ON quality_defect_flow_gaps '
            .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_validate_gap()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_reject_acceptance_retarget()
RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'acceptance_scopes'
       AND (NEW.organization_id <> OLD.organization_id OR NEW.project_id <> OLD.project_id)
       AND EXISTS (
            SELECT 1 FROM quality_defect_flow_events
            WHERE acceptance_scope_id = OLD.id
       ) THEN
        RAISE EXCEPTION 'quality defect flow acceptance scope cannot be retargeted' USING ERRCODE = '55000';
    END IF;

    IF TG_TABLE_NAME = 'acceptance_sessions'
       AND (
            NEW.organization_id <> OLD.organization_id
            OR NEW.project_id <> OLD.project_id
            OR NEW.acceptance_scope_id <> OLD.acceptance_scope_id
       )
       AND EXISTS (
            SELECT 1 FROM quality_defect_flow_events
            WHERE acceptance_session_id = OLD.id
       ) THEN
        RAISE EXCEPTION 'quality defect flow acceptance session cannot be retargeted' USING ERRCODE = '55000';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::unprepared(
            'CREATE TRIGGER acceptance_scopes_reject_quality_flow_retarget '
            .'BEFORE UPDATE ON acceptance_scopes '
            .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_reject_acceptance_retarget()',
        );
        DB::unprepared(
            'CREATE TRIGGER acceptance_sessions_reject_quality_flow_retarget '
            .'BEFORE UPDATE ON acceptance_sessions '
            .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_reject_acceptance_retarget()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION quality_defect_flow_reject_lineage_retarget()
RETURNS trigger AS $$
DECLARE
    new_row jsonb := to_jsonb(NEW);
    old_row jsonb := to_jsonb(OLD);
BEGIN
    IF TG_TABLE_NAME = 'quality_defects' THEN
        IF new_row->>'organization_id' <> old_row->>'organization_id'
           AND (
                EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE quality_defect_id = OLD.id)
                OR EXISTS (SELECT 1 FROM quality_defect_flow_gaps WHERE quality_defect_id = OLD.id)
           ) THEN
            RAISE EXCEPTION 'quality defect flow defect organization cannot be retargeted' USING ERRCODE = '55000';
        END IF;

        IF new_row->>'project_id' <> old_row->>'project_id'
           AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE quality_defect_id = OLD.id) THEN
            RAISE EXCEPTION 'quality defect flow defect project cannot be retargeted' USING ERRCODE = '55000';
        END IF;
    ELSIF TG_TABLE_NAME = 'projects'
       AND new_row->>'organization_id' <> old_row->>'organization_id'
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE project_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow project organization cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'contractors'
       AND new_row->>'organization_id' <> old_row->>'organization_id'
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE contractor_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow contractor organization cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'schedule_tasks'
       AND (
            new_row->>'organization_id' <> old_row->>'organization_id'
            OR new_row->>'schedule_id' <> old_row->>'schedule_id'
       )
       AND EXISTS (SELECT 1 FROM quality_defect_flow_events WHERE schedule_task_id = OLD.id) THEN
        RAISE EXCEPTION 'quality defect flow schedule task cannot be retargeted' USING ERRCODE = '55000';
    ELSIF TG_TABLE_NAME = 'project_schedules'
       AND (
            new_row->>'organization_id' <> old_row->>'organization_id'
            OR new_row->>'project_id' <> old_row->>'project_id'
       )
       AND EXISTS (
            SELECT 1
            FROM quality_defect_flow_events
            INNER JOIN schedule_tasks ON schedule_tasks.id = quality_defect_flow_events.schedule_task_id
            WHERE schedule_tasks.schedule_id = OLD.id
       ) THEN
        RAISE EXCEPTION 'quality defect flow project schedule cannot be retargeted' USING ERRCODE = '55000';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        foreach ([
            'quality_defects',
            'projects',
            'contractors',
            'schedule_tasks',
            'project_schedules',
        ] as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_reject_quality_flow_retarget "
                ."BEFORE UPDATE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION quality_defect_flow_reject_lineage_retarget()',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_reject_lineage_retarget() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_reject_acceptance_retarget() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_validate_gap() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_validate_event() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_validate_user_membership(bigint, bigint, bigint) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_validate_policy_pin(bigint, integer, text, text) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_reject_mutation() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_hash_json(jsonb) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS quality_defect_flow_canonical_json(jsonb) CASCADE');
        }

        Schema::dropIfExists('quality_defect_flow_gaps');
        Schema::dropIfExists('quality_defect_flow_events');
        Schema::dropIfExists('quality_defect_flow_policies');
    }
};
