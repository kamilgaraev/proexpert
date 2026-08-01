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
        Schema::create('lookahead_readiness_policy_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('policy_code');
            $table->string('semantic_version');
            $table->unsignedInteger('revision');
            $table->jsonb('canonical_definition');
            $table->char('policy_hash', 64);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedBigInteger('published_by_user_id');
            $table->timestampTz('published_at');
            $table->string('idempotency_key');
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'policy_code', 'revision'], 'lookahead_policy_revision_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'lookahead_policy_idempotency_unique');
            $table->index(['organization_id', 'policy_code', 'effective_from'], 'lookahead_policy_effective_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('schedule_plan_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedInteger('revision_number');
            $table->string('status');
            $table->char('content_hash', 64);
            $table->jsonb('canonical_snapshot');
            $table->string('source_watermark');
            $table->string('planning_timezone');
            $table->jsonb('planning_calendar');
            $table->char('planning_calendar_hash', 64);
            $table->unsignedBigInteger('predecessor_revision_id')->nullable();
            $table->timestampTz('approved_at');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->string('idempotency_key');
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'schedule_id', 'revision_number'], 'schedule_plan_revision_number_unique');
            $table->unique(['organization_id', 'schedule_id', 'approved_at'], 'schedule_plan_revision_effective_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'schedule_plan_revision_idempotency_unique');
            $table->unique(['id', 'organization_id', 'project_id', 'schedule_id'], 'schedule_plan_revision_lineage_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'approved_at'], 'schedule_plan_revision_as_of_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('schedule_id')->references('id')->on('project_schedules')->restrictOnDelete();
            $table->foreign('predecessor_revision_id')->references('id')->on('schedule_plan_revisions')->restrictOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('schedule_plan_revision_tasks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('schedule_plan_revision_id');
            $table->string('external_id');
            $table->unsignedBigInteger('source_task_id')->nullable();
            $table->string('wbs_code');
            $table->text('task_name');
            $table->string('task_class');
            $table->date('planned_start');
            $table->date('planned_end');
            $table->unsignedBigInteger('duration_minutes');
            $table->decimal('planned_quantity', 24, 4)->nullable();
            $table->decimal('planned_work_hours', 24, 4)->nullable();
            $table->boolean('is_critical');
            $table->string('constraint_point')->nullable();
            $table->string('parent_external_id')->nullable();
            $table->char('task_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['schedule_plan_revision_id', 'external_id'], 'schedule_plan_task_external_unique');
            $table->unique(['id', 'schedule_plan_revision_id', 'organization_id', 'project_id', 'schedule_id'], 'schedule_plan_task_lineage_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'planned_start', 'id'], 'schedule_plan_task_window_idx');
            $table->foreign(
                ['schedule_plan_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'schedule_plan_task_revision_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('schedule_plan_revisions')
                ->restrictOnDelete();
        });

        Schema::create('schedule_plan_revision_dependencies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('schedule_plan_revision_id');
            $table->string('predecessor_external_id');
            $table->string('successor_external_id');
            $table->string('dependency_type');
            $table->bigInteger('lag_minutes');
            $table->char('dependency_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(
                ['schedule_plan_revision_id', 'predecessor_external_id', 'successor_external_id', 'dependency_type'],
                'schedule_plan_dependency_unique',
            );
            $table->foreign(
                ['schedule_plan_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'schedule_plan_dependency_revision_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('schedule_plan_revisions')
                ->restrictOnDelete();
        });

        Schema::create('lookahead_commitment_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('schedule_plan_revision_id');
            $table->unsignedBigInteger('readiness_policy_version_id');
            $table->unsignedInteger('revision_number');
            $table->string('status');
            $table->date('window_start');
            $table->date('window_end');
            $table->string('planning_timezone');
            $table->char('schedule_revision_hash', 64);
            $table->char('policy_hash', 64);
            $table->char('content_hash', 64);
            $table->jsonb('canonical_snapshot');
            $table->timestampTz('published_at');
            $table->unsignedBigInteger('published_by_user_id');
            $table->string('idempotency_key');
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'schedule_id', 'revision_number'], 'lookahead_commitment_revision_unique');
            $table->unique(['organization_id', 'schedule_id', 'published_at'], 'lookahead_commitment_effective_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'lookahead_commitment_idempotency_unique');
            $table->unique(['id', 'organization_id', 'project_id', 'schedule_id'], 'lookahead_commitment_lineage_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'published_at'], 'lookahead_commitment_as_of_idx');
            $table->foreign(
                ['schedule_plan_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'lookahead_commitment_schedule_revision_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('schedule_plan_revisions')
                ->restrictOnDelete();
            $table->foreign('readiness_policy_version_id')->references('id')->on('lookahead_readiness_policy_versions')->restrictOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lookahead_commitment_tasks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('commitment_revision_id');
            $table->unsignedBigInteger('schedule_plan_revision_task_id');
            $table->string('schedule_task_external_id');
            $table->date('committed_start');
            $table->date('committed_end');
            $table->decimal('planned_quantity', 24, 4)->nullable();
            $table->decimal('planned_work_hours', 24, 4)->nullable();
            $table->string('responsible_role')->nullable();
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->string('inclusion_reason');
            $table->char('task_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['commitment_revision_id', 'schedule_plan_revision_task_id'], 'lookahead_commitment_task_unique');
            $table->unique(['id', 'commitment_revision_id', 'organization_id', 'project_id', 'schedule_id'], 'lookahead_commitment_task_lineage_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'committed_start', 'id'], 'lookahead_commitment_task_window_idx');
            $table->foreign(
                ['commitment_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'lookahead_commitment_task_revision_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('lookahead_commitment_revisions')
                ->restrictOnDelete();
            $table->foreign('schedule_plan_revision_task_id')->references('id')->on('schedule_plan_revision_tasks')->restrictOnDelete();
            $table->foreign('responsible_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lookahead_readiness_events', function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('commitment_revision_id');
            $table->unsignedBigInteger('commitment_task_id')->nullable();
            $table->unsignedBigInteger('readiness_policy_version_id');
            $table->string('event_type');
            $table->string('idempotency_key');
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('actor_id');
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->jsonb('evidence')->nullable();
            $table->char('evidence_hash', 64);
            $table->uuid('prior_event_id')->nullable();
            $table->char('policy_hash', 64);
            $table->char('schedule_revision_hash', 64);
            $table->timestampTz('created_at');

            $table->primary('event_id', 'lookahead_readiness_events_pk');
            $table->unique(['organization_id', 'idempotency_key'], 'lookahead_event_idempotency_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'occurred_at', 'event_id'], 'lookahead_event_cursor_idx');
            $table->index(['commitment_task_id', 'occurred_at', 'event_id'], 'lookahead_event_task_cursor_idx');
            $table->foreign(
                ['commitment_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'lookahead_event_commitment_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('lookahead_commitment_revisions')
                ->restrictOnDelete();
            $table->foreign('commitment_task_id')->references('id')->on('lookahead_commitment_tasks')->restrictOnDelete();
            $table->foreign('readiness_policy_version_id')->references('id')->on('lookahead_readiness_policy_versions')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('prior_event_id')->references('event_id')->on('lookahead_readiness_events')->restrictOnDelete();
        });

        Schema::create('lookahead_readiness_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('commitment_revision_id');
            $table->unsignedBigInteger('commitment_task_id');
            $table->unsignedBigInteger('readiness_policy_version_id');
            $table->unsignedInteger('snapshot_revision');
            $table->string('state');
            $table->jsonb('component_outcomes');
            $table->jsonb('reason_codes');
            $table->jsonb('blocker_event_ids');
            $table->jsonb('waiver_event_ids');
            $table->char('policy_hash', 64);
            $table->char('schedule_revision_hash', 64);
            $table->char('commitment_revision_hash', 64);
            $table->timestampTz('calculated_at');
            $table->string('source_watermark');
            $table->jsonb('actual_comparison')->nullable();
            $table->char('readiness_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->string('idempotency_key');
            $table->timestampTz('created_at');

            $table->unique(['commitment_task_id', 'snapshot_revision'], 'lookahead_snapshot_revision_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'lookahead_snapshot_idempotency_unique');
            $table->index(['organization_id', 'project_id', 'schedule_id', 'calculated_at', 'id'], 'lookahead_snapshot_as_of_idx');
            $table->index(['commitment_revision_id', 'state', 'id'], 'lookahead_snapshot_state_idx');
            $table->foreign(
                ['commitment_revision_id', 'organization_id', 'project_id', 'schedule_id'],
                'lookahead_snapshot_commitment_fk',
            )->references(['id', 'organization_id', 'project_id', 'schedule_id'])
                ->on('lookahead_commitment_revisions')
                ->restrictOnDelete();
            $table->foreign('commitment_task_id')->references('id')->on('lookahead_commitment_tasks')->restrictOnDelete();
            $table->foreign('readiness_policy_version_id')->references('id')->on('lookahead_readiness_policy_versions')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_canonical_json(value jsonb)
RETURNS text AS $$
DECLARE
    result text;
BEGIN
    CASE jsonb_typeof(value)
        WHEN 'object' THEN
            SELECT '{' || COALESCE(string_agg(to_jsonb(key)::text || ':' || lookahead_readiness_canonical_json(val), ',' ORDER BY key), '') || '}'
            INTO result
            FROM jsonb_each(value) AS entry(key, val);
        WHEN 'array' THEN
            SELECT '[' || COALESCE(string_agg(lookahead_readiness_canonical_json(val), ',' ORDER BY ordinality), '') || ']'
            INTO result
            FROM jsonb_array_elements(value) WITH ORDINALITY AS entry(val, ordinality);
        ELSE
            result := value::text;
    END CASE;

    RETURN result;
END;
$$ LANGUAGE plpgsql IMMUTABLE STRICT
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_hash_json(value jsonb)
RETURNS text AS $$
    SELECT encode(sha256(convert_to(lookahead_readiness_canonical_json(value), 'UTF8')), 'hex')
$$ LANGUAGE sql IMMUTABLE STRICT
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_reject_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'lookahead readiness source is append-only' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql
SQL);

        foreach ([
            'lookahead_readiness_policy_versions',
            'schedule_plan_revisions',
            'schedule_plan_revision_tasks',
            'schedule_plan_revision_dependencies',
            'lookahead_commitment_revisions',
            'lookahead_commitment_tasks',
            'lookahead_readiness_events',
            'lookahead_readiness_snapshots',
        ] as $table) {
            DB::unprepared(
                "CREATE TRIGGER {$table}_reject_mutation BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_reject_mutation()',
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_user_in_scope(
    target_user_id bigint,
    target_organization_id bigint,
    target_project_id bigint
) RETURNS boolean AS $$
DECLARE
    access_mode text;
BEGIN
    IF target_user_id IS NULL THEN
        RETURN true;
    END IF;

    SELECT project_access_mode INTO access_mode
    FROM organization_user
    WHERE organization_id = target_organization_id
      AND user_id = target_user_id
      AND is_active = true;
    IF NOT FOUND THEN
        RETURN false;
    END IF;
    IF access_mode IS DISTINCT FROM 'restricted' OR target_project_id IS NULL THEN
        RETURN true;
    END IF;

    RETURN EXISTS (
        SELECT 1 FROM project_user
        WHERE project_id = target_project_id
          AND user_id = target_user_id
          AND is_active = true
    );
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_policy()
RETURNS trigger AS $$
BEGIN
    IF NEW.policy_hash <> lookahead_readiness_hash_json(NEW.canonical_definition)
       OR NEW.effective_until IS NOT NULL AND NEW.effective_until <= NEW.effective_from THEN
        RAISE EXCEPTION 'lookahead readiness policy hash or interval mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.canonical_definition->>'organization_id' <> NEW.organization_id::text
       OR NEW.canonical_definition->>'policy_code' <> NEW.policy_code
       OR NEW.canonical_definition->>'semantic_version' <> NEW.semantic_version
       OR NEW.canonical_definition->>'revision' <> NEW.revision::text
       OR jsonb_typeof(NEW.canonical_definition->'task_classes') <> 'object'
       OR jsonb_typeof(NEW.canonical_definition#>'{task_classes,standard,required}') <> 'object'
       OR jsonb_typeof(NEW.canonical_definition->'waiver') <> 'object'
       OR jsonb_typeof(NEW.canonical_definition->'redaction_labels') <> 'object' THEN
        RAISE EXCEPTION 'lookahead readiness policy schema mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM organization_user
        WHERE organization_id = NEW.organization_id
          AND user_id = NEW.published_by_user_id
          AND is_active = true
    ) THEN
        RAISE EXCEPTION 'lookahead readiness policy publisher mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_readiness_policy_validate BEFORE INSERT ON lookahead_readiness_policy_versions FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_policy()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_schedule_revision()
RETURNS trigger AS $$
BEGIN
    IF NEW.status <> 'approved'
       OR NEW.content_hash <> lookahead_readiness_hash_json(NEW.canonical_snapshot)
       OR NEW.planning_calendar_hash <> lookahead_readiness_hash_json(NEW.planning_calendar)
       OR NEW.canonical_snapshot->>'organization_id' <> NEW.organization_id::text
       OR NEW.canonical_snapshot->>'project_id' <> NEW.project_id::text
       OR NEW.canonical_snapshot->>'schedule_id' <> NEW.schedule_id::text
       OR NEW.canonical_snapshot->>'source_watermark' <> NEW.source_watermark
       OR NEW.canonical_snapshot->>'planning_timezone' <> NEW.planning_timezone
       OR NOT EXISTS (
            SELECT 1 FROM project_schedules
            WHERE id = NEW.schedule_id
              AND organization_id = NEW.organization_id
              AND project_id = NEW.project_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule revision mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.predecessor_revision_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM schedule_plan_revisions
        WHERE id = NEW.predecessor_revision_id
          AND organization_id = NEW.organization_id
          AND project_id = NEW.project_id
          AND schedule_id = NEW.schedule_id
          AND revision_number = NEW.revision_number - 1
          AND approved_at <= NEW.approved_at
    ) THEN
        RAISE EXCEPTION 'lookahead readiness predecessor mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_user_in_scope(NEW.approved_by_user_id, NEW.organization_id, NEW.project_id) THEN
        RAISE EXCEPTION 'lookahead readiness schedule approver mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER schedule_plan_revision_validate BEFORE INSERT ON schedule_plan_revisions FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_revision()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_schedule_task()
RETURNS trigger AS $$
DECLARE
    revision schedule_plan_revisions%ROWTYPE;
    canonical_task jsonb;
BEGIN
    SELECT * INTO revision FROM schedule_plan_revisions
    WHERE id = NEW.schedule_plan_revision_id FOR KEY SHARE;

    SELECT item INTO canonical_task
    FROM jsonb_array_elements(revision.canonical_snapshot->'tasks') AS item
    WHERE item->>'external_id' = NEW.external_id;

    IF NOT FOUND
       OR NEW.organization_id <> revision.organization_id
       OR NEW.project_id <> revision.project_id
       OR NEW.schedule_id <> revision.schedule_id
       OR NEW.task_hash <> lookahead_readiness_hash_json(canonical_task)
       OR NEW.source_task_id::text IS DISTINCT FROM canonical_task->>'source_task_id'
       OR NEW.wbs_code <> canonical_task->>'wbs_code'
       OR NEW.task_name <> canonical_task->>'name'
       OR NEW.task_class <> canonical_task->>'task_class'
       OR NEW.planned_start::text <> canonical_task->>'planned_start'
       OR NEW.planned_end::text <> canonical_task->>'planned_end'
       OR NEW.duration_minutes::text <> canonical_task->>'duration_minutes'
       OR NEW.planned_quantity::text IS DISTINCT FROM canonical_task->>'planned_quantity'
       OR NEW.planned_work_hours::text IS DISTINCT FROM canonical_task->>'planned_work_hours'
       OR NEW.is_critical IS DISTINCT FROM (canonical_task->>'critical')::boolean
       OR NEW.constraint_point IS DISTINCT FROM canonical_task->>'constraint_point'
       OR NEW.parent_external_id IS DISTINCT FROM canonical_task->>'parent_external_id'
       OR NEW.planned_start > NEW.planned_end
       OR NEW.parent_external_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(revision.canonical_snapshot->'tasks') AS parent
            WHERE parent->>'external_id' = NEW.parent_external_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule task mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.source_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM schedule_tasks
        WHERE id = NEW.source_task_id
          AND organization_id = NEW.organization_id
          AND schedule_id = NEW.schedule_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness source task mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER schedule_plan_revision_task_validate BEFORE INSERT ON schedule_plan_revision_tasks FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_task()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_dependency()
RETURNS trigger AS $$
DECLARE
    revision schedule_plan_revisions%ROWTYPE;
    canonical_dependency jsonb;
BEGIN
    SELECT * INTO revision FROM schedule_plan_revisions
    WHERE id = NEW.schedule_plan_revision_id FOR KEY SHARE;

    SELECT item INTO canonical_dependency
    FROM jsonb_array_elements(revision.canonical_snapshot->'dependencies') AS item
    WHERE item->>'predecessor_external_id' = NEW.predecessor_external_id
      AND item->>'successor_external_id' = NEW.successor_external_id
      AND item->>'type' = NEW.dependency_type;

    IF NOT FOUND
       OR NEW.organization_id <> revision.organization_id
       OR NEW.project_id <> revision.project_id
       OR NEW.schedule_id <> revision.schedule_id
       OR NEW.dependency_hash <> lookahead_readiness_hash_json(canonical_dependency)
       OR NEW.lag_minutes::text <> canonical_dependency->>'lag_minutes'
       OR NOT EXISTS (
            SELECT 1 FROM schedule_plan_revision_tasks
            WHERE schedule_plan_revision_id = NEW.schedule_plan_revision_id
              AND external_id = NEW.predecessor_external_id
       )
       OR NOT EXISTS (
            SELECT 1 FROM schedule_plan_revision_tasks
            WHERE schedule_plan_revision_id = NEW.schedule_plan_revision_id
              AND external_id = NEW.successor_external_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness dependency mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER schedule_plan_revision_dependency_validate BEFORE INSERT ON schedule_plan_revision_dependencies FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_dependency()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_schedule_completeness()
RETURNS trigger AS $$
BEGIN
    IF jsonb_array_length(NEW.canonical_snapshot->'tasks') <> (
            SELECT count(*) FROM schedule_plan_revision_tasks
            WHERE schedule_plan_revision_id = NEW.id
       )
       OR jsonb_array_length(NEW.canonical_snapshot->'dependencies') <> (
            SELECT count(*) FROM schedule_plan_revision_dependencies
            WHERE schedule_plan_revision_id = NEW.id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule snapshot incomplete' USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared(
            'CREATE CONSTRAINT TRIGGER schedule_plan_revision_complete '
            .'AFTER INSERT ON schedule_plan_revisions DEFERRABLE INITIALLY DEFERRED '
            .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_completeness()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_commitment()
RETURNS trigger AS $$
DECLARE
    revision schedule_plan_revisions%ROWTYPE;
    policy lookahead_readiness_policy_versions%ROWTYPE;
BEGIN
    SELECT * INTO revision FROM schedule_plan_revisions WHERE id = NEW.schedule_plan_revision_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;

    IF NEW.status <> 'published'
       OR NEW.organization_id <> revision.organization_id
       OR NEW.project_id <> revision.project_id
       OR NEW.schedule_id <> revision.schedule_id
       OR NEW.organization_id <> policy.organization_id
       OR NEW.schedule_revision_hash <> revision.content_hash
       OR NEW.policy_hash <> policy.policy_hash
       OR NEW.content_hash <> lookahead_readiness_hash_json(NEW.canonical_snapshot)
       OR NEW.window_start > NEW.window_end
       OR NEW.planning_timezone <> revision.planning_timezone
       OR NEW.canonical_snapshot->>'schedule_revision_hash' <> NEW.schedule_revision_hash
       OR NEW.canonical_snapshot->>'policy_hash' <> NEW.policy_hash THEN
        RAISE EXCEPTION 'lookahead readiness commitment mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_user_in_scope(NEW.published_by_user_id, NEW.organization_id, NEW.project_id) THEN
        RAISE EXCEPTION 'lookahead readiness commitment publisher mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_commitment_validate BEFORE INSERT ON lookahead_commitment_revisions FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_commitment_task()
RETURNS trigger AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    schedule_task schedule_plan_revision_tasks%ROWTYPE;
    canonical_task jsonb;
BEGIN
    SELECT * INTO commitment FROM lookahead_commitment_revisions
    WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO schedule_task FROM schedule_plan_revision_tasks
    WHERE id = NEW.schedule_plan_revision_task_id FOR KEY SHARE;
    SELECT item INTO canonical_task
    FROM jsonb_array_elements(commitment.canonical_snapshot->'tasks') AS item
    WHERE item->>'schedule_task_external_id' = NEW.schedule_task_external_id;

    IF NOT FOUND
       OR NEW.organization_id <> commitment.organization_id
       OR NEW.project_id <> commitment.project_id
       OR NEW.schedule_id <> commitment.schedule_id
       OR schedule_task.schedule_plan_revision_id <> commitment.schedule_plan_revision_id
       OR schedule_task.external_id <> NEW.schedule_task_external_id
       OR NEW.committed_start::text <> canonical_task->>'committed_start'
       OR NEW.committed_end::text <> canonical_task->>'committed_end'
       OR NEW.planned_quantity::text IS DISTINCT FROM canonical_task->>'planned_quantity'
       OR NEW.planned_work_hours::text IS DISTINCT FROM canonical_task->>'planned_work_hours'
       OR NEW.responsible_role IS DISTINCT FROM canonical_task->>'responsible_role'
       OR NEW.responsible_user_id::text IS DISTINCT FROM canonical_task->>'responsible_user_id'
       OR NEW.inclusion_reason <> canonical_task->>'inclusion_reason'
       OR NEW.committed_start < commitment.window_start
       OR NEW.committed_start > commitment.window_end
       OR NEW.committed_start > NEW.committed_end
       OR NEW.task_hash <> lookahead_readiness_hash_json(canonical_task) THEN
        RAISE EXCEPTION 'lookahead readiness commitment task mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_user_in_scope(NEW.responsible_user_id, NEW.organization_id, NEW.project_id) THEN
        RAISE EXCEPTION 'lookahead readiness responsible user mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_commitment_task_validate BEFORE INSERT ON lookahead_commitment_tasks FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment_task()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_commitment_completeness()
RETURNS trigger AS $$
BEGIN
    IF jsonb_array_length(NEW.canonical_snapshot->'tasks') <> (
            SELECT count(*) FROM lookahead_commitment_tasks
            WHERE commitment_revision_id = NEW.id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness commitment snapshot incomplete' USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared(
            'CREATE CONSTRAINT TRIGGER lookahead_commitment_complete '
            .'AFTER INSERT ON lookahead_commitment_revisions DEFERRABLE INITIALLY DEFERRED '
            .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment_completeness()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_event()
RETURNS trigger AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    task lookahead_commitment_tasks%ROWTYPE;
    policy lookahead_readiness_policy_versions%ROWTYPE;
    prior lookahead_readiness_events%ROWTYPE;
    valid_until timestamptz;
    expected_evidence_hash text;
BEGIN
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;

    IF NEW.commitment_task_id IS NOT NULL THEN
        SELECT * INTO task FROM lookahead_commitment_tasks WHERE id = NEW.commitment_task_id FOR KEY SHARE;
    END IF;

    IF NEW.organization_id <> commitment.organization_id
       OR NEW.project_id <> commitment.project_id
       OR NEW.schedule_id <> commitment.schedule_id
       OR NEW.organization_id <> policy.organization_id
       OR NEW.policy_hash <> policy.policy_hash
       OR NEW.schedule_revision_hash <> commitment.schedule_revision_hash
       OR NEW.payload_hash <> lookahead_readiness_hash_json(NEW.payload)
       OR NEW.commitment_task_id IS NOT NULL AND (
            task.commitment_revision_id <> NEW.commitment_revision_id
            OR task.organization_id <> NEW.organization_id
            OR task.project_id <> NEW.project_id
            OR task.schedule_id <> NEW.schedule_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness event lineage mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type NOT IN (
        'constraint_registered',
        'constraint_evidence_attached',
        'constraint_resolved',
        'constraint_reopened',
        'readiness_evaluated',
        'waiver_requested',
        'waiver_approved',
        'waiver_rejected',
        'waiver_expired',
        'waiver_revoked',
        'commitment_published',
        'commitment_superseded',
        'commitment_withdrawn'
    ) THEN
        RAISE EXCEPTION 'lookahead readiness event type mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type IN (
        'constraint_registered',
        'constraint_evidence_attached',
        'constraint_resolved',
        'constraint_reopened',
        'readiness_evaluated',
        'waiver_requested',
        'waiver_approved',
        'waiver_rejected',
        'waiver_expired',
        'waiver_revoked'
    ) AND NEW.commitment_task_id IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness task event requires task lineage' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type IN ('constraint_resolved', 'constraint_reopened', 'constraint_evidence_attached')
       AND NEW.prior_event_id IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness constraint transition requires prior evidence' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type = 'constraint_registered' AND (
        coalesce(NEW.payload->>'category', '') = ''
        OR NEW.payload->>'severity' NOT IN ('hard', 'soft')
        OR coalesce(NEW.payload->>'owner_ref', '') = ''
    ) THEN
        RAISE EXCEPTION 'lookahead readiness constraint payload mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type = 'readiness_evaluated' AND (
        NEW.payload->>'policy_hash' <> NEW.policy_hash
        OR NEW.payload->>'schedule_revision_hash' <> NEW.schedule_revision_hash
        OR NEW.payload->>'state' NOT IN ('ready', 'blocked', 'at_risk', 'unknown', 'not_applicable')
        OR jsonb_typeof(NEW.payload->'component_outcomes') <> 'array'
        OR coalesce(NEW.payload->>'as_of_utc', '') = ''
    ) THEN
        RAISE EXCEPTION 'lookahead readiness evaluation payload mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type LIKE 'waiver_%' AND NEW.event_type <> 'waiver_requested'
       AND NEW.prior_event_id IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness waiver transition requires prior evidence' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type = 'commitment_published' AND (
        NEW.commitment_task_id IS NOT NULL
        OR NEW.payload->>'commitment_content_hash' <> commitment.content_hash
        OR NEW.payload->>'policy_hash' <> commitment.policy_hash
        OR NEW.payload->>'schedule_revision_hash' <> commitment.schedule_revision_hash
        OR NEW.payload->>'window_start' <> commitment.window_start::text
        OR NEW.payload->>'window_end' <> commitment.window_end::text
        OR (NEW.payload->>'task_count')::integer <> (
            SELECT count(*) FROM lookahead_commitment_tasks
            WHERE commitment_revision_id = commitment.id
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness commitment publication event mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type IN ('commitment_superseded', 'commitment_withdrawn')
       AND NEW.prior_event_id IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness commitment transition requires prior evidence' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_user_in_scope(NEW.actor_id, NEW.organization_id, NEW.project_id) THEN
        RAISE EXCEPTION 'lookahead readiness event actor mismatch' USING ERRCODE = '23514';
    END IF;

    expected_evidence_hash := lookahead_readiness_hash_json(jsonb_build_object(
        'actor_id', NEW.actor_id::text,
        'commitment_revision_id', NEW.commitment_revision_id::text,
        'commitment_task_id', CASE WHEN NEW.commitment_task_id IS NULL THEN NULL ELSE NEW.commitment_task_id::text END,
        'event_id', NEW.event_id::text,
        'event_type', NEW.event_type,
        'idempotency_key', NEW.idempotency_key,
        'organization_id', NEW.organization_id::text,
        'occurred_at_utc', to_char(NEW.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'payload_hash', NEW.payload_hash,
        'policy_hash', NEW.policy_hash,
        'project_id', NEW.project_id::text,
        'schedule_id', NEW.schedule_id::text,
        'evidence', NEW.evidence,
        'prior_event_id', NEW.prior_event_id::text
    ));
    IF NEW.evidence_hash <> expected_evidence_hash THEN
        RAISE EXCEPTION 'lookahead readiness event evidence hash mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.prior_event_id IS NOT NULL THEN
        SELECT * INTO prior FROM lookahead_readiness_events WHERE event_id = NEW.prior_event_id FOR KEY SHARE;
        IF NOT FOUND
           OR prior.commitment_revision_id <> NEW.commitment_revision_id
           OR prior.commitment_task_id IS DISTINCT FROM NEW.commitment_task_id
           OR prior.occurred_at > NEW.occurred_at THEN
            RAISE EXCEPTION 'lookahead readiness prior event mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NEW.event_type = 'waiver_approved' THEN
        valid_until := (NEW.payload->>'valid_until')::timestamptz;
        IF NEW.commitment_task_id IS NULL
           OR NEW.evidence IS NULL
           OR coalesce(trim(NEW.payload->>'reason'), '') = ''
           OR NEW.payload->>'approver_permission' <> 'schedule.readiness.waivers.approve'
           OR NEW.payload->>'schedule_revision_hash' <> commitment.schedule_revision_hash
           OR valid_until <= NEW.occurred_at
           OR valid_until > NEW.occurred_at + interval '168 hours'
           OR NOT lookahead_readiness_user_in_scope(NEW.actor_id, NEW.organization_id, NEW.project_id) THEN
            RAISE EXCEPTION 'lookahead readiness waiver mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_readiness_event_validate BEFORE INSERT ON lookahead_readiness_events FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_event()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_snapshot()
RETURNS trigger AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    task lookahead_commitment_tasks%ROWTYPE;
    plan_task schedule_plan_revision_tasks%ROWTYPE;
    policy lookahead_readiness_policy_versions%ROWTYPE;
    expected_readiness_hash text;
    expected_snapshot_hash text;
    required jsonb;
    required_count integer;
    component_count integer;
BEGIN
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO task FROM lookahead_commitment_tasks WHERE id = NEW.commitment_task_id FOR KEY SHARE;
    SELECT * INTO plan_task FROM schedule_plan_revision_tasks
    WHERE id = task.schedule_plan_revision_task_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;
    required := policy.canonical_definition#>ARRAY['task_classes', plan_task.task_class, 'required'];
    SELECT count(*) INTO required_count FROM jsonb_object_keys(required);
    SELECT count(*) INTO component_count FROM jsonb_array_elements(NEW.component_outcomes);

    IF NEW.state NOT IN ('ready', 'blocked', 'at_risk', 'unknown', 'not_applicable')
       OR jsonb_typeof(NEW.component_outcomes) <> 'array'
       OR jsonb_typeof(NEW.reason_codes) <> 'array'
       OR jsonb_typeof(NEW.blocker_event_ids) <> 'array'
       OR jsonb_typeof(NEW.waiver_event_ids) <> 'array'
       OR task.commitment_revision_id <> commitment.id
       OR task.id <> NEW.commitment_task_id
       OR NEW.organization_id <> commitment.organization_id
       OR NEW.project_id <> commitment.project_id
       OR NEW.schedule_id <> commitment.schedule_id
       OR NEW.organization_id <> policy.organization_id
       OR NEW.policy_hash <> policy.policy_hash
       OR NEW.schedule_revision_hash <> commitment.schedule_revision_hash
       OR NEW.commitment_revision_hash <> commitment.content_hash
       OR EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(NEW.blocker_event_ids) AS event_ref
            WHERE NOT EXISTS (
                SELECT 1 FROM lookahead_readiness_events
                WHERE event_id::text = event_ref
                  AND commitment_revision_id = NEW.commitment_revision_id
                  AND commitment_task_id = NEW.commitment_task_id
            )
       )
       OR EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(NEW.waiver_event_ids) AS event_ref
            WHERE NOT EXISTS (
                SELECT 1 FROM lookahead_readiness_events
                WHERE event_id::text = event_ref
                  AND commitment_revision_id = NEW.commitment_revision_id
                  AND commitment_task_id = NEW.commitment_task_id
                  AND event_type LIKE 'waiver_%'
            )
       ) THEN
        RAISE EXCEPTION 'lookahead readiness snapshot mismatch' USING ERRCODE = '23514';
    END IF;

    IF required IS NULL
       OR component_count <> (
            SELECT count(DISTINCT component->>'category')
            FROM jsonb_array_elements(NEW.component_outcomes) AS component
       )
       OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE NOT required ? (component->>'category')
               OR component->>'outcome' NOT IN (
                    'satisfied', 'waived', 'unsatisfied', 'expiring', 'unknown', 'not_applicable'
               )
       ) THEN
        RAISE EXCEPTION 'lookahead readiness snapshot component mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.state = 'ready' AND (
        component_count <> required_count
        OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' NOT IN ('satisfied', 'waived')
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness ready formula mismatch' USING ERRCODE = '23514';
    ELSIF NEW.state = 'blocked' AND (
        component_count <> required_count
        OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'unknown'
        )
        OR NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'unsatisfied'
              AND (required->(component->>'category')->>'hard')::boolean = true
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness blocked formula mismatch' USING ERRCODE = '23514';
    ELSIF NEW.state = 'at_risk' AND (
        component_count <> required_count
        OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'unknown'
               OR component->>'outcome' = 'unsatisfied'
                  AND (required->(component->>'category')->>'hard')::boolean = true
        )
        OR NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'expiring'
               OR component->>'outcome' = 'unsatisfied'
                  AND (required->(component->>'category')->>'hard')::boolean = false
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness risk formula mismatch' USING ERRCODE = '23514';
    ELSIF NEW.state = 'unknown' AND NOT (
        component_count <> required_count
        OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'unknown'
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness unknown formula mismatch' USING ERRCODE = '23514';
    ELSIF NEW.state = 'not_applicable' AND (
        component_count <> required_count
        OR EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' NOT IN ('satisfied', 'waived', 'not_applicable')
               OR component->>'outcome' = 'not_applicable'
                  AND coalesce((component->>'policy_declared')::boolean, false) = false
        )
        OR NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.component_outcomes) AS component
            WHERE component->>'outcome' = 'not_applicable'
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness not-applicable formula mismatch' USING ERRCODE = '23514';
    END IF;

    expected_readiness_hash := lookahead_readiness_hash_json(jsonb_build_object(
        'blocker_event_ids', NEW.blocker_event_ids,
        'calculated_at_utc', to_char(NEW.calculated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'commitment_revision_hash', NEW.commitment_revision_hash,
        'commitment_revision_id', NEW.commitment_revision_id::text,
        'commitment_task_id', NEW.commitment_task_id::text,
        'component_outcomes', NEW.component_outcomes,
        'organization_id', NEW.organization_id::text,
        'policy_hash', NEW.policy_hash,
        'project_id', NEW.project_id::text,
        'reason_codes', NEW.reason_codes,
        'schedule_id', NEW.schedule_id::text,
        'schedule_revision_hash', NEW.schedule_revision_hash,
        'snapshot_revision', NEW.snapshot_revision,
        'source_watermark', NEW.source_watermark,
        'state', NEW.state,
        'waiver_event_ids', NEW.waiver_event_ids
    ));
    expected_snapshot_hash := lookahead_readiness_hash_json(jsonb_build_object(
        'blocker_event_ids', NEW.blocker_event_ids,
        'calculated_at_utc', to_char(NEW.calculated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'commitment_revision_hash', NEW.commitment_revision_hash,
        'commitment_revision_id', NEW.commitment_revision_id::text,
        'commitment_task_id', NEW.commitment_task_id::text,
        'component_outcomes', NEW.component_outcomes,
        'organization_id', NEW.organization_id::text,
        'policy_hash', NEW.policy_hash,
        'project_id', NEW.project_id::text,
        'reason_codes', NEW.reason_codes,
        'schedule_id', NEW.schedule_id::text,
        'schedule_revision_hash', NEW.schedule_revision_hash,
        'snapshot_revision', NEW.snapshot_revision,
        'source_watermark', NEW.source_watermark,
        'state', NEW.state,
        'waiver_event_ids', NEW.waiver_event_ids,
        'actual_comparison', NEW.actual_comparison,
        'readiness_hash', expected_readiness_hash
    ));
    IF NEW.readiness_hash <> expected_readiness_hash OR NEW.snapshot_hash <> expected_snapshot_hash THEN
        RAISE EXCEPTION 'lookahead readiness snapshot hash mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.actual_comparison IS NOT NULL THEN
        RAISE EXCEPTION 'lookahead readiness actual source unavailable' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_readiness_snapshot_validate BEFORE INSERT ON lookahead_readiness_snapshots FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_snapshot()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_snapshot() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_event() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_completeness() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_task() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_completeness() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_dependency() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_task() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_revision() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_policy() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_reject_mutation() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_user_in_scope(bigint, bigint, bigint) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_hash_json(jsonb) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_canonical_json(jsonb) CASCADE');
        }

        Schema::dropIfExists('lookahead_readiness_snapshots');
        Schema::dropIfExists('lookahead_readiness_events');
        Schema::dropIfExists('lookahead_commitment_tasks');
        Schema::dropIfExists('lookahead_commitment_revisions');
        Schema::dropIfExists('schedule_plan_revision_dependencies');
        Schema::dropIfExists('schedule_plan_revision_tasks');
        Schema::dropIfExists('schedule_plan_revisions');
        Schema::dropIfExists('lookahead_readiness_policy_versions');
    }
};
