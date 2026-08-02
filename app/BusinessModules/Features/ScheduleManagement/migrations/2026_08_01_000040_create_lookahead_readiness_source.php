<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookahead_readiness_system_role_definitions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('role_slug');
            $table->jsonb('canonical_definition');
            $table->char('definition_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['role_slug', 'definition_hash'], 'lookahead_system_role_definition_version_unique');
        });

        $systemRoles = [];
        foreach (['system', 'lk', 'admin', 'mobile', 'project', 'customer'] as $directory) {
            $path = base_path("config/RoleDefinitions/{$directory}");
            if (! File::isDirectory($path)) {
                continue;
            }
            foreach (File::files($path) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }
                $definition = json_decode(File::get($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($definition) && is_string($definition['slug'] ?? null)) {
                    $systemRoles[$definition['slug']] = $definition;
                }
            }
        }
        foreach ($systemRoles as $roleSlug => $definition) {
            DB::table('lookahead_readiness_system_role_definitions')->insert([
                'role_slug' => $roleSlug,
                'canonical_definition' => \App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson::encode($definition),
                'definition_hash' => \App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson::hash($definition),
                'created_at' => now('UTC'),
            ]);
        }

        Schema::create('lookahead_readiness_policy_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('policy_code');
            $table->string('semantic_version');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('predecessor_policy_version_id')->nullable();
            $table->jsonb('canonical_definition');
            $table->char('policy_hash', 64);
            $table->char('intent_hash', 64);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->unsignedBigInteger('published_by_user_id');
            $table->timestampTz('published_at');
            $table->string('idempotency_key');
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['organization_id', 'policy_code', 'revision'], 'lookahead_policy_revision_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'lookahead_policy_idempotency_unique');
            $table->index(['organization_id', 'policy_code', 'effective_from'], 'lookahead_policy_effective_idx');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('published_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('predecessor_policy_version_id')->references('id')->on('lookahead_readiness_policy_versions')->restrictOnDelete();
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
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
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

        Schema::create('schedule_plan_revision_lifecycle_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('schedule_plan_revision_id');
            $table->unsignedInteger('sequence');
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->timestampTz('effective_at');
            $table->unsignedBigInteger('actor_id');
            $table->string('idempotency_key');
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['schedule_plan_revision_id', 'sequence'], 'schedule_revision_lifecycle_sequence_unique');
            $table->unique(['schedule_plan_revision_id', 'idempotency_key'], 'schedule_revision_lifecycle_idempotency_unique');
            $table->foreign('schedule_plan_revision_id')->references('id')->on('schedule_plan_revisions')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
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
            $table->unsignedBigInteger('predecessor_revision_id')->nullable();
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
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
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
            $table->foreign('predecessor_revision_id')->references('id')->on('lookahead_commitment_revisions')->restrictOnDelete();
        });

        Schema::create('lookahead_commitment_lifecycle_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('commitment_revision_id');
            $table->unsignedInteger('sequence');
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->timestampTz('effective_at');
            $table->unsignedBigInteger('actor_id');
            $table->string('idempotency_key');
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
            $table->timestampTz('created_at');

            $table->unique(['commitment_revision_id', 'sequence'], 'commitment_lifecycle_sequence_unique');
            $table->unique(['commitment_revision_id', 'idempotency_key'], 'commitment_lifecycle_idempotency_unique');
            $table->foreign('commitment_revision_id')->references('id')->on('lookahead_commitment_revisions')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
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
            $table->string('aggregate_id');
            $table->jsonb('payload');
            $table->char('payload_hash', 64);
            $table->jsonb('evidence')->nullable();
            $table->char('evidence_hash', 64);
            $table->uuid('prior_event_id')->nullable();
            $table->char('policy_hash', 64);
            $table->char('schedule_revision_hash', 64);
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
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
            $table->unsignedBigInteger('predecessor_snapshot_id')->nullable();
            $table->string('state');
            $table->jsonb('component_outcomes');
            $table->jsonb('reason_codes');
            $table->jsonb('blocker_event_ids');
            $table->jsonb('waiver_event_ids');
            $table->char('policy_hash', 64);
            $table->char('schedule_revision_hash', 64);
            $table->char('commitment_revision_hash', 64);
            $table->timestampTz('calculated_at');
            $table->timestampTz('as_of');
            $table->string('source_watermark');
            $table->uuid('evaluation_event_id');
            $table->unsignedBigInteger('sealed_by_actor_id');
            $table->jsonb('actual_comparison')->nullable();
            $table->char('readiness_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->string('idempotency_key');
            $table->char('command_hash', 64);
            $table->jsonb('authorization_decision');
            $table->char('authorization_decision_hash', 64);
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
            $table->foreign('predecessor_snapshot_id')->references('id')->on('lookahead_readiness_snapshots')->restrictOnDelete();
            $table->foreign('evaluation_event_id')->references('event_id')->on('lookahead_readiness_events')->restrictOnDelete();
            $table->foreign('sealed_by_actor_id')->references('id')->on('users')->restrictOnDelete();
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
            'lookahead_readiness_system_role_definitions',
            'schedule_plan_revisions',
            'schedule_plan_revision_lifecycle_events',
            'schedule_plan_revision_tasks',
            'schedule_plan_revision_dependencies',
            'lookahead_commitment_revisions',
            'lookahead_commitment_lifecycle_events',
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
    IF access_mode = 'all_projects' THEN
        RETURN true;
    END IF;

    IF access_mode IS DISTINCT FROM 'assigned_projects' OR target_project_id IS NULL THEN
        RETURN false;
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
CREATE OR REPLACE FUNCTION lookahead_readiness_lock_schedule_source()
RETURNS trigger AS $$
DECLARE
    old_schedule_id bigint;
    new_schedule_id bigint;
BEGIN
    old_schedule_id := CASE WHEN TG_OP IN ('UPDATE', 'DELETE') THEN OLD.schedule_id ELSE NULL END;
    new_schedule_id := CASE WHEN TG_OP IN ('INSERT', 'UPDATE') THEN NEW.schedule_id ELSE NULL END;
    IF old_schedule_id IS NOT NULL THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('lookahead-schedule-source:' || old_schedule_id::text, 0));
    END IF;
    IF new_schedule_id IS NOT NULL AND new_schedule_id IS DISTINCT FROM old_schedule_id THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('lookahead-schedule-source:' || new_schedule_id::text, 0));
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared(
            'CREATE TRIGGER schedule_tasks_lookahead_source_lock '
            .'BEFORE INSERT OR UPDATE OR DELETE ON schedule_tasks '
            .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_lock_schedule_source()',
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_authorization_decision(
    decision jsonb,
    decision_hash text,
    expected_actor_id bigint,
    expected_permission text,
    expected_organization_id bigint,
    expected_project_id bigint
) RETURNS boolean AS $$
DECLARE
    factors jsonb;
    membership jsonb;
    project_membership jsonb;
    grants jsonb;
    access_mode text;
BEGIN
    IF jsonb_typeof(decision) IS DISTINCT FROM 'object'
       OR decision_hash IS DISTINCT FROM lookahead_readiness_hash_json(decision)
       OR decision->>'actor_id' IS DISTINCT FROM expected_actor_id::text
       OR decision->>'permission' IS DISTINCT FROM expected_permission
       OR decision->>'organization_id' IS DISTINCT FROM expected_organization_id::text
       OR decision->>'project_id' IS DISTINCT FROM expected_project_id::text
       OR jsonb_typeof(decision->'granting_assignments') IS DISTINCT FROM 'array'
       OR jsonb_array_length(decision->'granting_assignments') = 0
       OR jsonb_typeof(decision->'role_slugs') IS DISTINCT FROM 'array'
       OR jsonb_typeof(decision->'context_factors') IS DISTINCT FROM 'object' THEN
        RETURN false;
    END IF;
    IF (decision->>'decided_at_utc')::timestamptz < transaction_timestamp()
       OR (decision->>'decided_at_utc')::timestamptz > statement_timestamp() + interval '1 second' THEN
        RETURN false;
    END IF;

    factors := decision->'context_factors';
    membership := factors->'organization_membership';
    project_membership := factors->'project_membership';
    grants := decision->'granting_assignments';
    access_mode := membership->>'project_access_mode';
    IF jsonb_typeof(membership) IS DISTINCT FROM 'object'
       OR membership->>'organization_id' IS DISTINCT FROM expected_organization_id::text
       OR access_mode NOT IN ('all_projects', 'assigned_projects')
       OR decision->>'role_revision' IS DISTINCT FROM lookahead_readiness_hash_json(grants)
       OR decision->>'grant_revision' IS DISTINCT FROM lookahead_readiness_hash_json(jsonb_build_object(
            'context_factors', factors,
            'granting_assignments', grants,
            'permission', expected_permission
       )) THEN
        RETURN false;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM organization_user organization_membership
        WHERE organization_membership.organization_id = expected_organization_id
          AND organization_membership.user_id = expected_actor_id
          AND organization_membership.is_active = true
          AND organization_membership.project_access_mode = access_mode
          AND to_char(organization_membership.updated_at, 'YYYY-MM-DD HH24:MI:SS')
              IS NOT DISTINCT FROM membership->>'updated_at'
    ) THEN
        RETURN false;
    END IF;

    IF access_mode = 'assigned_projects' AND expected_project_id > 0 AND (
        jsonb_typeof(project_membership) IS DISTINCT FROM 'object'
        OR project_membership->>'project_id' IS DISTINCT FROM expected_project_id::text
        OR project_membership->>'user_id' IS DISTINCT FROM expected_actor_id::text
        OR project_membership->'is_active' IS DISTINCT FROM 'true'::jsonb
    ) THEN
        RETURN false;
    END IF;

    IF access_mode = 'assigned_projects' AND expected_project_id > 0 AND NOT EXISTS (
        SELECT 1 FROM project_user scoped_project_membership
        WHERE scoped_project_membership.project_id = expected_project_id
          AND scoped_project_membership.user_id = expected_actor_id
          AND scoped_project_membership.is_active = true
          AND to_char(scoped_project_membership.updated_at, 'YYYY-MM-DD HH24:MI:SS')
              IS NOT DISTINCT FROM project_membership->>'updated_at'
    ) THEN
        RETURN false;
    END IF;

    IF decision->'role_slugs' IS DISTINCT FROM (
        SELECT COALESCE(jsonb_agg(role_slug ORDER BY role_slug), '[]'::jsonb)
        FROM (
            SELECT DISTINCT grant->>'role_slug' AS role_slug
            FROM jsonb_array_elements(grants) AS grant
        ) AS distinct_roles
    ) THEN
        RETURN false;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM jsonb_array_elements(grants) AS grant
        WHERE NOT EXISTS (
            SELECT 1
            FROM user_role_assignments assignment
            WHERE assignment.id::text = grant->>'assignment_id'
              AND assignment.user_id = expected_actor_id
              AND assignment.context_id::text = grant->>'context_id'
              AND assignment.role_slug = grant->>'role_slug'
              AND assignment.role_type = grant->>'role_type'
              AND to_char(assignment.updated_at, 'YYYY-MM-DD HH24:MI:SS') IS NOT DISTINCT FROM grant->>'assignment_updated_at'
              AND assignment.is_active = true
              AND (assignment.expires_at IS NULL OR assignment.expires_at > statement_timestamp())
              AND EXISTS (
                  SELECT 1 FROM authorization_contexts context
                  WHERE context.id = assignment.context_id
                    AND (
                        context.type = 'system' AND context.resource_id IS NULL AND context.parent_context_id IS NULL
                        OR context.type = 'organization' AND context.resource_id = expected_organization_id
                        OR context.type = 'project' AND expected_project_id > 0 AND context.resource_id = expected_project_id
                           AND EXISTS (
                               SELECT 1 FROM authorization_contexts organization_context
                               WHERE organization_context.id = context.parent_context_id
                                 AND organization_context.type = 'organization'
                                 AND organization_context.resource_id = expected_organization_id
                           )
                    )
              )
              AND grant->>'role_definition_hash' IS NOT DISTINCT FROM lookahead_readiness_hash_json(grant->'role_definition')
              AND grant->>'matched_permission' IS NOT NULL
              AND (
                  grant->>'matched_permission' = '*'
                  OR grant->>'matched_permission' = expected_permission
                  OR right(grant->>'matched_permission', 1) = '*'
                     AND starts_with(expected_permission, left(grant->>'matched_permission', -1))
              )
              AND (
                  grant->'role_definition'->'system_permissions' ? (grant->>'matched_permission')
                  OR EXISTS (
                      SELECT 1
                      FROM jsonb_each(grant->'role_definition'->'module_permissions') AS module(module_slug, permissions),
                           jsonb_array_elements_text(module.permissions) AS module_permission(permission_slug)
                      WHERE module_permission.permission_slug = grant->>'matched_permission'
                         OR module.module_slug || '.' || module_permission.permission_slug = grant->>'matched_permission'
                  )
              )
              AND (
                  assignment.role_type = 'system' AND EXISTS (
                      SELECT 1 FROM lookahead_readiness_system_role_definitions definition
                      WHERE definition.role_slug = assignment.role_slug
                        AND definition.definition_hash = grant->>'role_definition_hash'
                        AND definition.canonical_definition = grant->'role_definition'
                  )
                  OR assignment.role_type = 'custom' AND EXISTS (
                      SELECT 1 FROM organization_custom_roles custom_role
                      WHERE custom_role.organization_id = expected_organization_id
                        AND custom_role.slug = assignment.role_slug
                        AND custom_role.is_active = true
                        AND grant->'role_definition' = jsonb_build_object(
                            'is_active', custom_role.is_active,
                            'module_permissions', custom_role.module_permissions::jsonb,
                            'organization_id', custom_role.organization_id::text,
                            'slug', custom_role.slug,
                            'system_permissions', custom_role.system_permissions::jsonb,
                            'updated_at', to_char(custom_role.updated_at, 'YYYY-MM-DD HH24:MI:SS')
                        )
                  )
              )
              AND grant->>'conditions_hash' IS NOT DISTINCT FROM (
                  SELECT lookahead_readiness_hash_json(COALESCE(jsonb_agg(jsonb_build_object(
                      'condition_data', condition.condition_data::jsonb,
                      'condition_type', condition.condition_type::text,
                      'id', condition.id::text,
                      'updated_at', to_char(condition.updated_at, 'YYYY-MM-DD HH24:MI:SS')
                  ) ORDER BY condition.id), '[]'::jsonb))
                  FROM role_conditions condition
                  WHERE condition.assignment_id = assignment.id
                    AND condition.is_active = true
              )
              AND NOT EXISTS (
                  SELECT 1 FROM role_conditions condition
                  WHERE condition.assignment_id = assignment.id
                    AND condition.is_active = true
              )
        )
    ) THEN
        RETURN false;
    END IF;

    RETURN lookahead_readiness_user_in_scope(
        expected_actor_id,
        expected_organization_id,
        NULLIF(expected_project_id, 0)
    );
EXCEPTION WHEN OTHERS THEN
    RETURN false;
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_policy_definition_valid(definition jsonb)
RETURNS boolean AS $$
DECLARE
    task_class record;
    requirement record;
    waiver jsonb;
BEGIN
    IF jsonb_typeof(definition) IS DISTINCT FROM 'object'
       OR NOT (definition ?& ARRAY[
            'business_calendar', 'deterministic_order', 'evidence_types', 'organization_id',
            'policy_code', 'redaction_labels', 'revision', 'schema_version', 'semantic_version',
            'task_classes', 'waiver'
       ])
       OR (SELECT count(*) FROM jsonb_object_keys(definition)) <> 11
       OR definition->'schema_version' IS DISTINCT FROM '1'::jsonb
       OR jsonb_typeof(definition->'business_calendar') IS DISTINCT FROM 'object'
       OR NOT (definition->'business_calendar' ?& ARRAY[
            'cutoff_local_time', 'dst_ambiguity', 'dst_gap', 'interval',
            'start_date_inclusion', 'timezone_source'
       ])
       OR (SELECT count(*) FROM jsonb_object_keys(definition->'business_calendar')) <> 6
       OR definition#>>'{business_calendar,cutoff_local_time}' !~ '^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$'
       OR definition#>>'{business_calendar,dst_ambiguity}' NOT IN ('reject', 'earlier', 'later')
       OR definition#>>'{business_calendar,dst_gap}' NOT IN ('reject', 'next_valid')
       OR definition#>>'{business_calendar,interval}' IS DISTINCT FROM 'left_closed_right_open'
       OR definition#>>'{business_calendar,start_date_inclusion}' IS DISTINCT FROM 'committed_start_in_window'
       OR definition#>>'{business_calendar,timezone_source}' IS DISTINCT FROM 'commitment'
       OR definition->'deterministic_order' IS DISTINCT FROM '["occurred_at_utc","event_id"]'::jsonb
       OR jsonb_typeof(definition->'evidence_types') IS DISTINCT FROM 'array'
       OR jsonb_array_length(definition->'evidence_types') = 0
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(definition->'evidence_types') item WHERE jsonb_typeof(item) <> 'string' OR item #>> '{}' = '')
       OR (SELECT count(*) FROM jsonb_array_elements_text(definition->'evidence_types'))
          <> (SELECT count(DISTINCT item) FROM jsonb_array_elements_text(definition->'evidence_types') item)
       OR jsonb_typeof(definition->'redaction_labels') IS DISTINCT FROM 'object'
       OR jsonb_typeof(definition->'task_classes') IS DISTINCT FROM 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(definition->'task_classes')) = 0
       OR jsonb_typeof(definition->'waiver') IS DISTINCT FROM 'object' THEN
        RETURN false;
    END IF;

    FOR task_class IN SELECT key, value FROM jsonb_each(definition->'task_classes')
    LOOP
        IF task_class.key = ''
           OR jsonb_typeof(task_class.value) IS DISTINCT FROM 'object'
           OR NOT (task_class.value ? 'required')
           OR (SELECT count(*) FROM jsonb_object_keys(task_class.value)) <> 1
           OR jsonb_typeof(task_class.value->'required') IS DISTINCT FROM 'object'
           OR (SELECT count(*) FROM jsonb_object_keys(task_class.value->'required')) = 0 THEN
            RETURN false;
        END IF;
        FOR requirement IN SELECT key, value FROM jsonb_each(task_class.value->'required')
        LOOP
            IF requirement.key = ''
               OR jsonb_typeof(requirement.value) IS DISTINCT FROM 'object'
               OR NOT (requirement.value ?& ARRAY[
                    'absence', 'allowed_evidence_types', 'evidence_required',
                    'expiry_threshold_hours', 'hard', 'not_applicable'
               ])
               OR (SELECT count(*) FROM jsonb_object_keys(requirement.value)) <> 6
               OR requirement.value->>'absence' NOT IN ('unknown', 'blocked', 'not_applicable')
               OR jsonb_typeof(requirement.value->'allowed_evidence_types') IS DISTINCT FROM 'array'
               OR jsonb_array_length(requirement.value->'allowed_evidence_types') = 0
               OR EXISTS (
                    SELECT 1 FROM jsonb_array_elements(requirement.value->'allowed_evidence_types') item
                    WHERE jsonb_typeof(item) <> 'string'
                       OR item #>> '{}' = ''
                       OR NOT (definition->'evidence_types' ? (item #>> '{}'))
               )
               OR (SELECT count(*) FROM jsonb_array_elements_text(requirement.value->'allowed_evidence_types'))
                  <> (SELECT count(DISTINCT item) FROM jsonb_array_elements_text(requirement.value->'allowed_evidence_types') item)
               OR jsonb_typeof(requirement.value->'evidence_required') IS DISTINCT FROM 'boolean'
               OR jsonb_typeof(requirement.value->'hard') IS DISTINCT FROM 'boolean'
               OR jsonb_typeof(requirement.value->'not_applicable') IS DISTINCT FROM 'boolean'
               OR jsonb_typeof(requirement.value->'expiry_threshold_hours') IS DISTINCT FROM 'number'
               OR requirement.value->>'expiry_threshold_hours' !~ '^[0-9]+$' THEN
                RETURN false;
            END IF;
        END LOOP;
    END LOOP;

    waiver := definition->'waiver';
    IF NOT (waiver ?& ARRAY[
        'allowed_categories', 'approver_permission', 'cross_schedule_revision',
        'evidence_required', 'max_validity_hours', 'reason_required', 'revalidation'
    ])
       OR (SELECT count(*) FROM jsonb_object_keys(waiver)) <> 7
       OR jsonb_typeof(waiver->'allowed_categories') IS DISTINCT FROM 'array'
       OR jsonb_array_length(waiver->'allowed_categories') = 0
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(waiver->'allowed_categories') item WHERE jsonb_typeof(item) <> 'string' OR item #>> '{}' = '')
       OR (SELECT count(*) FROM jsonb_array_elements_text(waiver->'allowed_categories'))
          <> (SELECT count(DISTINCT item) FROM jsonb_array_elements_text(waiver->'allowed_categories') item)
       OR waiver->>'approver_permission' IS DISTINCT FROM 'schedule.readiness.waivers.approve'
       OR waiver->'cross_schedule_revision' IS DISTINCT FROM 'false'::jsonb
       OR waiver->'evidence_required' IS DISTINCT FROM 'true'::jsonb
       OR waiver->'reason_required' IS DISTINCT FROM 'true'::jsonb
       OR jsonb_typeof(waiver->'max_validity_hours') IS DISTINCT FROM 'number'
       OR waiver->>'max_validity_hours' !~ '^[1-9][0-9]*$'
       OR waiver->>'revalidation' IS DISTINCT FROM 'on_schedule_or_policy_revision' THEN
        RETURN false;
    END IF;
    RETURN true;
EXCEPTION WHEN OTHERS THEN
    RETURN false;
END;
$$ LANGUAGE plpgsql IMMUTABLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_policy()
RETURNS trigger AS $$
DECLARE
    previous lookahead_readiness_policy_versions%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'lookahead-policy-number:' || NEW.organization_id::text || ':' || NEW.policy_code,
        0
    ));
    SELECT * INTO previous
    FROM lookahead_readiness_policy_versions
    WHERE organization_id = NEW.organization_id AND policy_code = NEW.policy_code
    ORDER BY revision DESC LIMIT 1 FOR UPDATE;

    IF NEW.policy_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.canonical_definition)
       OR NEW.intent_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.canonical_definition - 'revision')
       OR NEW.published_at < NEW.effective_from
       OR NEW.effective_until IS NOT NULL AND NEW.effective_until <= NEW.effective_from THEN
        RAISE EXCEPTION 'lookahead readiness policy hash or interval mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_policy_definition_valid(NEW.canonical_definition)
       OR NEW.canonical_definition->>'organization_id' IS DISTINCT FROM NEW.organization_id::text
       OR NEW.canonical_definition->>'policy_code' IS DISTINCT FROM NEW.policy_code
       OR NEW.canonical_definition->>'semantic_version' IS DISTINCT FROM NEW.semantic_version
       OR NEW.canonical_definition->>'revision' IS DISTINCT FROM NEW.revision::text
       THEN
        RAISE EXCEPTION 'lookahead readiness policy schema mismatch' USING ERRCODE = '23514';
    END IF;

    IF (previous.id IS NULL AND (NEW.revision IS DISTINCT FROM 1 OR NEW.predecessor_policy_version_id IS NOT NULL))
       OR (previous.id IS NOT NULL AND (
            NEW.revision IS DISTINCT FROM previous.revision + 1
            OR NEW.predecessor_policy_version_id IS DISTINCT FROM previous.id
            OR NEW.published_at < previous.published_at
       )) THEN
        RAISE EXCEPTION 'lookahead readiness policy predecessor mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_validate_authorization_decision(
        NEW.authorization_decision,
        NEW.authorization_decision_hash,
        NEW.published_by_user_id,
        'schedule.readiness.policies.publish',
        NEW.organization_id,
        0
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
DECLARE
    previous schedule_plan_revisions%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'schedule-revision-number:' || NEW.organization_id::text || ':' || NEW.schedule_id::text,
        0
    ));
    SELECT * INTO previous FROM schedule_plan_revisions
    WHERE organization_id = NEW.organization_id AND schedule_id = NEW.schedule_id
    ORDER BY revision_number DESC LIMIT 1 FOR UPDATE;

    IF NEW.status IS DISTINCT FROM 'approved'
       OR jsonb_typeof(NEW.canonical_snapshot) IS DISTINCT FROM 'object'
       OR jsonb_typeof(NEW.canonical_snapshot->'tasks') IS DISTINCT FROM 'array'
       OR jsonb_typeof(NEW.canonical_snapshot->'dependencies') IS DISTINCT FROM 'array'
       OR NEW.content_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.canonical_snapshot)
       OR NEW.planning_calendar_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.planning_calendar)
       OR NEW.canonical_snapshot->>'organization_id' IS DISTINCT FROM NEW.organization_id::text
       OR NEW.canonical_snapshot->>'project_id' IS DISTINCT FROM NEW.project_id::text
       OR NEW.canonical_snapshot->>'schedule_id' IS DISTINCT FROM NEW.schedule_id::text
       OR NEW.canonical_snapshot->>'source_watermark' IS DISTINCT FROM NEW.source_watermark
       OR NEW.canonical_snapshot->>'planning_timezone' IS DISTINCT FROM NEW.planning_timezone
       OR NOT EXISTS (
            SELECT 1 FROM project_schedules
            WHERE id = NEW.schedule_id
              AND organization_id = NEW.organization_id
              AND project_id = NEW.project_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule revision mismatch' USING ERRCODE = '23514';
    END IF;

    IF (previous.id IS NULL AND (NEW.revision_number IS DISTINCT FROM 1 OR NEW.predecessor_revision_id IS NOT NULL))
       OR (previous.id IS NOT NULL AND (
            NEW.revision_number IS DISTINCT FROM previous.revision_number + 1
            OR NEW.predecessor_revision_id IS DISTINCT FROM previous.id
            OR NEW.project_id IS DISTINCT FROM previous.project_id
            OR NEW.approved_at < previous.approved_at
            OR coalesce((SELECT lifecycle.to_state FROM schedule_plan_revision_lifecycle_events lifecycle
                WHERE lifecycle.schedule_plan_revision_id = previous.id
                ORDER BY lifecycle.sequence DESC LIMIT 1), '') NOT IN ('approved', 'superseded', 'withdrawn')
       )) THEN
        RAISE EXCEPTION 'lookahead readiness predecessor mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_validate_authorization_decision(
        NEW.authorization_decision,
        NEW.authorization_decision_hash,
        NEW.approved_by_user_id,
        'schedule.readiness.schedule_revisions.approve',
        NEW.organization_id,
        NEW.project_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule approver mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER schedule_plan_revision_validate BEFORE INSERT ON schedule_plan_revisions FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_revision()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_schedule_lifecycle()
RETURNS trigger AS $$
DECLARE
    revision schedule_plan_revisions%ROWTYPE;
    previous schedule_plan_revision_lifecycle_events%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('schedule-revision-lifecycle:' || NEW.schedule_plan_revision_id::text, 0));
    SELECT * INTO revision FROM schedule_plan_revisions WHERE id = NEW.schedule_plan_revision_id FOR KEY SHARE;
    SELECT * INTO previous FROM schedule_plan_revision_lifecycle_events
    WHERE schedule_plan_revision_id = NEW.schedule_plan_revision_id
    ORDER BY sequence DESC LIMIT 1 FOR UPDATE;
    IF revision.id IS NULL
       OR NEW.effective_at < revision.created_at
       OR (previous.id IS NULL AND (
            NEW.sequence IS DISTINCT FROM 1
            OR NEW.from_state IS NOT NULL
            OR NEW.to_state IS DISTINCT FROM 'draft'
       ))
       OR (previous.id IS NOT NULL AND (
            NEW.sequence IS DISTINCT FROM previous.sequence + 1
            OR NEW.from_state IS DISTINCT FROM previous.to_state
            OR NEW.effective_at < previous.effective_at
            OR NOT (
                previous.to_state = 'draft' AND NEW.to_state = 'approved'
                OR previous.to_state = 'approved' AND NEW.to_state IN ('superseded', 'withdrawn')
            )
       ))
       OR NOT lookahead_readiness_validate_authorization_decision(
            NEW.authorization_decision,
            NEW.authorization_decision_hash,
            NEW.actor_id,
            'schedule.readiness.schedule_revisions.approve',
            revision.organization_id,
            revision.project_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule lifecycle mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER schedule_plan_revision_lifecycle_validate BEFORE INSERT ON schedule_plan_revision_lifecycle_events FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_lifecycle()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_schedule_effectivity()
RETURNS trigger AS $$
BEGIN
    IF (SELECT lifecycle.to_state FROM schedule_plan_revision_lifecycle_events lifecycle
        WHERE lifecycle.schedule_plan_revision_id = NEW.id
        ORDER BY lifecycle.sequence DESC LIMIT 1) IS DISTINCT FROM 'approved'
       OR (NEW.predecessor_revision_id IS NOT NULL AND coalesce((
            SELECT lifecycle.to_state FROM schedule_plan_revision_lifecycle_events lifecycle
            WHERE lifecycle.schedule_plan_revision_id = NEW.predecessor_revision_id
            ORDER BY lifecycle.sequence DESC LIMIT 1
       ), '') NOT IN ('superseded', 'withdrawn'))
       OR 1 < (
            SELECT count(*) FROM schedule_plan_revisions revision
            WHERE revision.organization_id = NEW.organization_id
              AND revision.schedule_id = NEW.schedule_id
              AND (SELECT lifecycle.to_state FROM schedule_plan_revision_lifecycle_events lifecycle
                   WHERE lifecycle.schedule_plan_revision_id = revision.id
                   ORDER BY lifecycle.sequence DESC LIMIT 1) = 'approved'
       ) THEN
        RAISE EXCEPTION 'lookahead readiness schedule effectivity mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared(
            'CREATE CONSTRAINT TRIGGER schedule_plan_revision_effective '
            .'AFTER INSERT ON schedule_plan_revisions DEFERRABLE INITIALLY DEFERRED '
            .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_schedule_effectivity()',
        );

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
       OR NOT (canonical_task ?& ARRAY[
            'external_id', 'source_task_id', 'wbs_code', 'name', 'task_class', 'planned_start',
            'planned_end', 'duration_minutes', 'planned_quantity', 'planned_work_hours',
            'critical', 'constraint_point', 'parent_external_id'
       ])
       OR NEW.organization_id IS DISTINCT FROM revision.organization_id
       OR NEW.project_id IS DISTINCT FROM revision.project_id
       OR NEW.schedule_id IS DISTINCT FROM revision.schedule_id
       OR NEW.task_hash IS DISTINCT FROM lookahead_readiness_hash_json(canonical_task)
       OR NEW.source_task_id::text IS DISTINCT FROM canonical_task->>'source_task_id'
       OR NEW.wbs_code IS DISTINCT FROM canonical_task->>'wbs_code'
       OR NEW.task_name IS DISTINCT FROM canonical_task->>'name'
       OR NEW.task_class IS DISTINCT FROM canonical_task->>'task_class'
       OR NEW.planned_start::text IS DISTINCT FROM canonical_task->>'planned_start'
       OR NEW.planned_end::text IS DISTINCT FROM canonical_task->>'planned_end'
       OR NEW.duration_minutes::text IS DISTINCT FROM canonical_task->>'duration_minutes'
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
          AND deleted_at IS NULL
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
       OR NOT (canonical_dependency ?& ARRAY[
            'predecessor_external_id', 'successor_external_id', 'type', 'lag_minutes'
       ])
       OR NEW.organization_id IS DISTINCT FROM revision.organization_id
       OR NEW.project_id IS DISTINCT FROM revision.project_id
       OR NEW.schedule_id IS DISTINCT FROM revision.schedule_id
       OR NEW.dependency_hash IS DISTINCT FROM lookahead_readiness_hash_json(canonical_dependency)
       OR NEW.lag_minutes::text IS DISTINCT FROM canonical_dependency->>'lag_minutes'
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
    previous lookahead_commitment_revisions%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'lookahead-commitment-number:' || NEW.organization_id::text || ':' || NEW.schedule_id::text,
        0
    ));
    SELECT * INTO revision FROM schedule_plan_revisions WHERE id = NEW.schedule_plan_revision_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;
    SELECT * INTO previous FROM lookahead_commitment_revisions
    WHERE organization_id = NEW.organization_id AND schedule_id = NEW.schedule_id
    ORDER BY revision_number DESC LIMIT 1 FOR UPDATE;

    IF NEW.status IS DISTINCT FROM 'published'
       OR revision.id IS NULL
       OR policy.id IS NULL
       OR jsonb_typeof(NEW.canonical_snapshot) IS DISTINCT FROM 'object'
       OR jsonb_typeof(NEW.canonical_snapshot->'tasks') IS DISTINCT FROM 'array'
       OR NEW.organization_id IS DISTINCT FROM revision.organization_id
       OR NEW.project_id IS DISTINCT FROM revision.project_id
       OR NEW.schedule_id IS DISTINCT FROM revision.schedule_id
       OR NEW.organization_id IS DISTINCT FROM policy.organization_id
       OR NEW.schedule_revision_hash IS DISTINCT FROM revision.content_hash
       OR NEW.policy_hash IS DISTINCT FROM policy.policy_hash
       OR NEW.content_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.canonical_snapshot)
       OR NEW.window_start > NEW.window_end
       OR NEW.planning_timezone IS DISTINCT FROM revision.planning_timezone
       OR NEW.canonical_snapshot->>'schedule_revision_hash' IS DISTINCT FROM NEW.schedule_revision_hash
       OR NEW.canonical_snapshot->>'policy_hash' IS DISTINCT FROM NEW.policy_hash
       OR NEW.published_at < revision.approved_at
       OR (SELECT lifecycle.to_state FROM schedule_plan_revision_lifecycle_events lifecycle
           WHERE lifecycle.schedule_plan_revision_id = revision.id
           ORDER BY lifecycle.sequence DESC LIMIT 1) IS DISTINCT FROM 'approved'
       OR NEW.published_at < policy.published_at
       OR NEW.published_at < policy.effective_from
       OR policy.effective_until IS NOT NULL AND NEW.published_at >= policy.effective_until THEN
        RAISE EXCEPTION 'lookahead readiness commitment mismatch' USING ERRCODE = '23514';
    END IF;

    IF (previous.id IS NULL AND (NEW.revision_number IS DISTINCT FROM 1 OR NEW.predecessor_revision_id IS NOT NULL))
       OR (previous.id IS NOT NULL AND (
            NEW.revision_number IS DISTINCT FROM previous.revision_number + 1
            OR NEW.predecessor_revision_id IS DISTINCT FROM previous.id
            OR NEW.project_id IS DISTINCT FROM previous.project_id
            OR NEW.published_at < previous.published_at
            OR coalesce((SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
                WHERE lifecycle.commitment_revision_id = previous.id
                ORDER BY lifecycle.sequence DESC LIMIT 1), '') NOT IN ('published', 'superseded', 'withdrawn')
       )) THEN
        RAISE EXCEPTION 'lookahead readiness commitment predecessor mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_validate_authorization_decision(
        NEW.authorization_decision,
        NEW.authorization_decision_hash,
        NEW.published_by_user_id,
        'schedule.readiness.commitments.publish',
        NEW.organization_id,
        NEW.project_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness commitment publisher mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_commitment_validate BEFORE INSERT ON lookahead_commitment_revisions FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_commitment_lifecycle()
RETURNS trigger AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    previous lookahead_commitment_lifecycle_events%ROWTYPE;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('lookahead-commitment-lifecycle:' || NEW.commitment_revision_id::text, 0));
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO previous FROM lookahead_commitment_lifecycle_events
    WHERE commitment_revision_id = NEW.commitment_revision_id
    ORDER BY sequence DESC LIMIT 1 FOR UPDATE;
    IF commitment.id IS NULL
       OR NEW.effective_at < commitment.created_at
       OR (previous.id IS NULL AND (
            NEW.sequence IS DISTINCT FROM 1
            OR NEW.from_state IS NOT NULL
            OR NEW.to_state IS DISTINCT FROM 'draft'
       ))
       OR (previous.id IS NOT NULL AND (
            NEW.sequence IS DISTINCT FROM previous.sequence + 1
            OR NEW.from_state IS DISTINCT FROM previous.to_state
            OR NEW.effective_at < previous.effective_at
            OR NOT (
                previous.to_state = 'draft' AND NEW.to_state = 'published'
                OR previous.to_state = 'published' AND NEW.to_state IN ('superseded', 'withdrawn')
            )
       ))
       OR NOT lookahead_readiness_validate_authorization_decision(
            NEW.authorization_decision,
            NEW.authorization_decision_hash,
            NEW.actor_id,
            'schedule.readiness.commitments.publish',
            commitment.organization_id,
            commitment.project_id
       ) THEN
        RAISE EXCEPTION 'lookahead readiness commitment lifecycle mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER lookahead_commitment_lifecycle_validate BEFORE INSERT ON lookahead_commitment_lifecycle_events FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment_lifecycle()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_commitment_effectivity()
RETURNS trigger AS $$
BEGIN
    IF (SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
        WHERE lifecycle.commitment_revision_id = NEW.id
        ORDER BY lifecycle.sequence DESC LIMIT 1) IS DISTINCT FROM 'published'
       OR (NEW.predecessor_revision_id IS NOT NULL AND coalesce((
            SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
            WHERE lifecycle.commitment_revision_id = NEW.predecessor_revision_id
            ORDER BY lifecycle.sequence DESC LIMIT 1
       ), '') NOT IN ('superseded', 'withdrawn'))
       OR 1 < (
            SELECT count(*) FROM lookahead_commitment_revisions revision
            WHERE revision.organization_id = NEW.organization_id
              AND revision.schedule_id = NEW.schedule_id
              AND (SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
                   WHERE lifecycle.commitment_revision_id = revision.id
                   ORDER BY lifecycle.sequence DESC LIMIT 1) = 'published'
       ) THEN
        RAISE EXCEPTION 'lookahead readiness commitment effectivity mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared(
            'CREATE CONSTRAINT TRIGGER lookahead_commitment_effective '
            .'AFTER INSERT ON lookahead_commitment_revisions DEFERRABLE INITIALLY DEFERRED '
            .'FOR EACH ROW EXECUTE FUNCTION lookahead_readiness_validate_commitment_effectivity()',
        );

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
       OR commitment.id IS NULL
       OR schedule_task.id IS NULL
       OR NEW.organization_id IS DISTINCT FROM commitment.organization_id
       OR NEW.project_id IS DISTINCT FROM commitment.project_id
       OR NEW.schedule_id IS DISTINCT FROM commitment.schedule_id
       OR schedule_task.schedule_plan_revision_id IS DISTINCT FROM commitment.schedule_plan_revision_id
       OR schedule_task.external_id IS DISTINCT FROM NEW.schedule_task_external_id
       OR NEW.committed_start::text IS DISTINCT FROM canonical_task->>'committed_start'
       OR NEW.committed_end::text IS DISTINCT FROM canonical_task->>'committed_end'
       OR NEW.planned_quantity::text IS DISTINCT FROM canonical_task->>'planned_quantity'
       OR NEW.planned_work_hours::text IS DISTINCT FROM canonical_task->>'planned_work_hours'
       OR NEW.responsible_role IS DISTINCT FROM canonical_task->>'responsible_role'
       OR NEW.responsible_user_id::text IS DISTINCT FROM canonical_task->>'responsible_user_id'
       OR NEW.inclusion_reason IS DISTINCT FROM canonical_task->>'inclusion_reason'
       OR NEW.committed_start < commitment.window_start
       OR NEW.committed_start > commitment.window_end
       OR NEW.committed_end > commitment.window_end
       OR NEW.committed_start > NEW.committed_end
       OR NEW.task_hash IS DISTINCT FROM lookahead_readiness_hash_json(canonical_task) THEN
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
CREATE OR REPLACE FUNCTION lookahead_readiness_expected_evaluation(
    target_commitment_revision_id bigint,
    target_commitment_task_id bigint,
    target_policy_version_id bigint,
    target_as_of timestamptz
) RETURNS jsonb AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    policy lookahead_readiness_policy_versions%ROWTYPE;
    plan_task schedule_plan_revision_tasks%ROWTYPE;
    rule_entry record;
    waiver_event lookahead_readiness_events%ROWTYPE;
    resolved_event lookahead_readiness_events%ROWTYPE;
    resolved_evidence jsonb;
    open_event_ids jsonb;
    components jsonb := '[]'::jsonb;
    reasons jsonb := '[]'::jsonb;
    blocker_ids jsonb := '[]'::jsonb;
    waiver_ids jsonb := '[]'::jsonb;
    consumed_ids jsonb;
    source_watermark text;
    outcome text;
    reason text;
    has_unknown boolean := false;
    has_blocked boolean := false;
    has_risk boolean := false;
    has_not_applicable boolean := false;
    evaluation_state text;
BEGIN
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = target_commitment_revision_id;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = target_policy_version_id;
    SELECT schedule_task.* INTO plan_task
    FROM lookahead_commitment_tasks commitment_task
    JOIN schedule_plan_revision_tasks schedule_task ON schedule_task.id = commitment_task.schedule_plan_revision_task_id
    WHERE commitment_task.id = target_commitment_task_id
      AND commitment_task.commitment_revision_id = target_commitment_revision_id;
    IF commitment.id IS NULL OR policy.id IS NULL OR plan_task.id IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness evaluation lineage missing' USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(jsonb_agg(event_id::text ORDER BY occurred_at, event_id), '[]'::jsonb),
           encode(sha256(convert_to(COALESCE(string_agg(event_id::text, E'\n' ORDER BY occurred_at, event_id), ''), 'UTF8')), 'hex')
    INTO consumed_ids, source_watermark
    FROM lookahead_readiness_events
    WHERE commitment_revision_id = target_commitment_revision_id
      AND commitment_task_id = target_commitment_task_id
      AND readiness_policy_version_id = target_policy_version_id
      AND occurred_at <= target_as_of
      AND event_type <> 'readiness_evaluated';

    FOR rule_entry IN
        SELECT required.key AS category, required.value AS rule
        FROM jsonb_each(policy.canonical_definition#>ARRAY['task_classes', plan_task.task_class, 'required']) AS required
        ORDER BY required.key
    LOOP
        waiver_event.event_id := NULL;
        resolved_event.event_id := NULL;
        resolved_evidence := NULL;
        open_event_ids := '[]'::jsonb;
        outcome := NULL;
        reason := NULL;

        WITH aggregate_first AS (
            SELECT aggregate_id, min(occurred_at) AS first_at, min(event_id::text)::uuid AS first_id
            FROM lookahead_readiness_events
            WHERE commitment_revision_id = target_commitment_revision_id
              AND commitment_task_id = target_commitment_task_id
              AND readiness_policy_version_id = target_policy_version_id
              AND occurred_at <= target_as_of
              AND event_type LIKE 'waiver_%'
            GROUP BY aggregate_id
        ), aggregate_latest AS (
            SELECT DISTINCT ON (event.aggregate_id) event.*, aggregate_first.first_at, aggregate_first.first_id
            FROM lookahead_readiness_events event
            JOIN aggregate_first USING (aggregate_id)
            WHERE event.commitment_revision_id = target_commitment_revision_id
              AND event.commitment_task_id = target_commitment_task_id
              AND event.readiness_policy_version_id = target_policy_version_id
              AND event.occurred_at <= target_as_of
              AND event.event_type LIKE 'waiver_%'
            ORDER BY event.aggregate_id, event.occurred_at DESC, event.event_id DESC
        )
        SELECT aggregate_latest.* INTO waiver_event
        FROM aggregate_latest
        WHERE aggregate_latest.event_type = 'waiver_approved'
          AND aggregate_latest.payload->>'category' = rule_entry.category
          AND policy.canonical_definition#>'{waiver,allowed_categories}' ? rule_entry.category
          AND (aggregate_latest.payload->>'valid_until')::timestamptz > target_as_of
          AND aggregate_latest.payload->>'schedule_revision_hash' = commitment.schedule_revision_hash
          AND aggregate_latest.authorization_decision->>'permission' = 'schedule.readiness.waivers.approve'
          AND aggregate_latest.evidence IS NOT NULL
          AND policy.canonical_definition->'evidence_types' ? (aggregate_latest.evidence->>'type')
          AND rule_entry.rule->'allowed_evidence_types' ? (aggregate_latest.evidence->>'type')
          AND coalesce(aggregate_latest.evidence->>'locator', '') <> ''
          AND coalesce(aggregate_latest.evidence->>'version', '') <> ''
          AND aggregate_latest.evidence->>'hash' ~ '^[a-f0-9]{64}$'
        ORDER BY aggregate_latest.first_at DESC, aggregate_latest.first_id DESC
        LIMIT 1;

        IF waiver_event.event_id IS NOT NULL THEN
            outcome := 'waived';
            waiver_ids := waiver_ids || jsonb_build_array(waiver_event.event_id::text);
        ELSE
            WITH aggregate_first AS (
                SELECT aggregate_id, min(occurred_at) AS first_at, min(event_id::text)::uuid AS first_id
                FROM lookahead_readiness_events
                WHERE commitment_revision_id = target_commitment_revision_id
                  AND commitment_task_id = target_commitment_task_id
                  AND readiness_policy_version_id = target_policy_version_id
                  AND occurred_at <= target_as_of
                  AND event_type LIKE 'constraint_%'
                GROUP BY aggregate_id
            ), aggregate_latest AS (
                SELECT DISTINCT ON (event.aggregate_id) event.*, aggregate_first.first_at, aggregate_first.first_id
                FROM lookahead_readiness_events event
                JOIN aggregate_first USING (aggregate_id)
                WHERE event.commitment_revision_id = target_commitment_revision_id
                  AND event.commitment_task_id = target_commitment_task_id
                  AND event.readiness_policy_version_id = target_policy_version_id
                  AND event.occurred_at <= target_as_of
                  AND event.event_type LIKE 'constraint_%'
                ORDER BY event.aggregate_id, event.occurred_at DESC, event.event_id DESC
            )
            SELECT COALESCE(jsonb_agg(event_id::text ORDER BY first_at, first_id), '[]'::jsonb)
            INTO open_event_ids
            FROM aggregate_latest
            WHERE payload->>'category' = rule_entry.category
              AND event_type IN ('constraint_registered', 'constraint_evidence_attached', 'constraint_reopened');

            IF jsonb_array_length(open_event_ids) > 0 THEN
                outcome := 'unsatisfied';
                blocker_ids := blocker_ids || open_event_ids;
                IF (rule_entry.rule->>'hard')::boolean THEN
                    has_blocked := true;
                    reason := 'hard_prerequisite_unsatisfied';
                ELSE
                    has_risk := true;
                    reason := 'soft_prerequisite_unsatisfied';
                END IF;
            ELSE
                WITH aggregate_first AS (
                    SELECT aggregate_id, min(occurred_at) AS first_at, min(event_id::text)::uuid AS first_id
                    FROM lookahead_readiness_events
                    WHERE commitment_revision_id = target_commitment_revision_id
                      AND commitment_task_id = target_commitment_task_id
                      AND readiness_policy_version_id = target_policy_version_id
                      AND occurred_at <= target_as_of
                      AND event_type LIKE 'constraint_%'
                    GROUP BY aggregate_id
                ), aggregate_latest AS (
                    SELECT DISTINCT ON (event.aggregate_id) event.*, aggregate_first.first_at, aggregate_first.first_id
                    FROM lookahead_readiness_events event
                    JOIN aggregate_first USING (aggregate_id)
                    WHERE event.commitment_revision_id = target_commitment_revision_id
                      AND event.commitment_task_id = target_commitment_task_id
                      AND event.readiness_policy_version_id = target_policy_version_id
                      AND event.occurred_at <= target_as_of
                      AND event.event_type LIKE 'constraint_%'
                    ORDER BY event.aggregate_id, event.occurred_at DESC, event.event_id DESC
                )
                SELECT aggregate_latest.* INTO resolved_event
                FROM aggregate_latest
                WHERE payload->>'category' = rule_entry.category
                  AND event_type = 'constraint_resolved'
                ORDER BY first_at DESC, first_id DESC
                LIMIT 1;

                IF resolved_event.event_id IS NOT NULL THEN
                    SELECT event.evidence INTO resolved_evidence
                    FROM lookahead_readiness_events event
                    WHERE event.aggregate_id = resolved_event.aggregate_id
                      AND event.commitment_revision_id = target_commitment_revision_id
                      AND event.commitment_task_id = target_commitment_task_id
                      AND (event.occurred_at, event.event_id) <= (resolved_event.occurred_at, resolved_event.event_id)
                      AND event.evidence IS NOT NULL
                    ORDER BY event.occurred_at DESC, event.event_id DESC
                    LIMIT 1;
                    IF (rule_entry.rule->>'evidence_required')::boolean
                       AND (
                            resolved_evidence IS NULL
                            OR NOT (policy.canonical_definition->'evidence_types' ? (resolved_evidence->>'type'))
                            OR NOT (rule_entry.rule->'allowed_evidence_types' ? (resolved_evidence->>'type'))
                            OR coalesce(resolved_evidence->>'locator', '') = ''
                            OR coalesce(resolved_evidence->>'version', '') = ''
                            OR resolved_evidence->>'hash' !~ '^[a-f0-9]{64}$'
                       ) THEN
                        outcome := 'unknown';
                        has_unknown := true;
                        reason := 'required_evidence_missing';
                    ELSE
                        outcome := 'satisfied';
                    END IF;
                ELSIF rule_entry.rule->>'absence' = 'blocked' THEN
                    outcome := 'unsatisfied';
                    IF (rule_entry.rule->>'hard')::boolean THEN
                        has_blocked := true;
                        reason := 'hard_prerequisite_unsatisfied';
                    ELSE
                        has_risk := true;
                        reason := 'soft_prerequisite_unsatisfied';
                    END IF;
                ELSIF rule_entry.rule->>'absence' = 'not_applicable'
                      AND (rule_entry.rule->>'not_applicable')::boolean THEN
                    outcome := 'not_applicable';
                    has_not_applicable := true;
                ELSE
                    outcome := 'unknown';
                    has_unknown := true;
                    reason := 'component_contradictory_or_unknown';
                END IF;
            END IF;
        END IF;

        components := components || jsonb_build_array(
            CASE WHEN outcome = 'not_applicable'
                THEN jsonb_build_object('category', rule_entry.category, 'outcome', outcome, 'policy_declared', true)
                ELSE jsonb_build_object('category', rule_entry.category, 'outcome', outcome)
            END
        );
        IF reason IS NOT NULL AND NOT (reasons ? reason) THEN
            reasons := reasons || jsonb_build_array(reason);
        END IF;
    END LOOP;

    evaluation_state := CASE
        WHEN has_unknown THEN 'unknown'
        WHEN has_blocked THEN 'blocked'
        WHEN has_risk THEN 'at_risk'
        WHEN has_not_applicable THEN 'not_applicable'
        ELSE 'ready'
    END;
    RETURN jsonb_build_object(
        'blocker_event_ids', blocker_ids,
        'component_outcomes', components,
        'consumed_event_ids', consumed_ids,
        'reason_codes', reasons,
        'source_watermark', source_watermark,
        'state', evaluation_state,
        'waiver_event_ids', waiver_ids
    );
END;
$$ LANGUAGE plpgsql STABLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_readiness_validate_event()
RETURNS trigger AS $$
DECLARE
    commitment lookahead_commitment_revisions%ROWTYPE;
    task lookahead_commitment_tasks%ROWTYPE;
    plan_task schedule_plan_revision_tasks%ROWTYPE;
    policy lookahead_readiness_policy_versions%ROWTYPE;
    prior lookahead_readiness_events%ROWTYPE;
    valid_until timestamptz;
    expected_evidence_hash text;
    expected_permission text;
    expected_consumed_event_ids jsonb;
    expected_source_watermark text;
    expected_evaluation jsonb;
BEGIN
    IF NEW.commitment_task_id IS NOT NULL THEN
        PERFORM pg_advisory_xact_lock(hashtextextended(
            'lookahead-task-event-stream:' || NEW.organization_id::text || ':' || NEW.commitment_task_id::text,
            0
        ));
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'lookahead-event-aggregate:' || NEW.organization_id::text || ':' || NEW.aggregate_id,
        0
    ));
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;

    IF NEW.commitment_task_id IS NOT NULL THEN
        SELECT * INTO task FROM lookahead_commitment_tasks WHERE id = NEW.commitment_task_id FOR KEY SHARE;
        SELECT * INTO plan_task FROM schedule_plan_revision_tasks
        WHERE id = task.schedule_plan_revision_task_id FOR KEY SHARE;
    END IF;

    IF commitment.id IS NULL
       OR policy.id IS NULL
       OR jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object'
       OR NEW.organization_id IS DISTINCT FROM commitment.organization_id
       OR NEW.project_id IS DISTINCT FROM commitment.project_id
       OR NEW.schedule_id IS DISTINCT FROM commitment.schedule_id
       OR NEW.organization_id IS DISTINCT FROM policy.organization_id
       OR NEW.readiness_policy_version_id IS DISTINCT FROM commitment.readiness_policy_version_id
       OR NEW.policy_hash IS DISTINCT FROM policy.policy_hash
       OR NEW.schedule_revision_hash IS DISTINCT FROM commitment.schedule_revision_hash
       OR NEW.payload_hash IS DISTINCT FROM lookahead_readiness_hash_json(NEW.payload)
       OR NEW.occurred_at < commitment.published_at
       OR NEW.commitment_task_id IS NOT NULL AND (
            task.id IS NULL
            OR task.commitment_revision_id IS DISTINCT FROM NEW.commitment_revision_id
            OR task.organization_id IS DISTINCT FROM NEW.organization_id
            OR task.project_id IS DISTINCT FROM NEW.project_id
            OR task.schedule_id IS DISTINCT FROM NEW.schedule_id
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

    IF (NEW.event_type LIKE 'constraint_%' AND NEW.aggregate_id NOT LIKE 'constraint:%')
       OR (NEW.event_type LIKE 'waiver_%' AND NEW.aggregate_id NOT LIKE 'waiver:%')
       OR (NEW.event_type LIKE 'commitment_%' AND NEW.aggregate_id IS DISTINCT FROM 'commitment:' || NEW.commitment_revision_id::text)
       OR (NEW.event_type = 'readiness_evaluated' AND NEW.aggregate_id NOT LIKE 'evaluation:%') THEN
        RAISE EXCEPTION 'lookahead readiness aggregate mismatch' USING ERRCODE = '23514';
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
        NEW.payload->>'policy_hash' IS DISTINCT FROM NEW.policy_hash
        OR NEW.payload->>'schedule_revision_hash' IS DISTINCT FROM NEW.schedule_revision_hash
        OR NEW.payload->>'state' NOT IN ('ready', 'blocked', 'at_risk', 'unknown', 'not_applicable')
        OR jsonb_typeof(NEW.payload->'component_outcomes') IS DISTINCT FROM 'array'
        OR jsonb_typeof(NEW.payload->'reason_codes') IS DISTINCT FROM 'array'
        OR jsonb_typeof(NEW.payload->'consumed_event_ids') IS DISTINCT FROM 'array'
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
        OR NEW.payload->>'commitment_content_hash' IS DISTINCT FROM commitment.content_hash
        OR NEW.payload->>'policy_hash' IS DISTINCT FROM commitment.policy_hash
        OR NEW.payload->>'schedule_revision_hash' IS DISTINCT FROM commitment.schedule_revision_hash
        OR NEW.payload->>'window_start' IS DISTINCT FROM commitment.window_start::text
        OR NEW.payload->>'window_end' IS DISTINCT FROM commitment.window_end::text
        OR (NEW.payload->>'task_count')::integer IS DISTINCT FROM (
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

    IF (SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
        WHERE lifecycle.commitment_revision_id = NEW.commitment_revision_id
        ORDER BY lifecycle.sequence DESC LIMIT 1) IS DISTINCT FROM 'published' THEN
        RAISE EXCEPTION 'lookahead readiness commitment is not effective' USING ERRCODE = '23514';
    END IF;

    expected_permission := CASE
        WHEN NEW.event_type = 'readiness_evaluated' THEN 'schedule.readiness.evaluations.seal'
        WHEN NEW.event_type IN ('waiver_approved', 'waiver_rejected', 'waiver_expired', 'waiver_revoked') THEN 'schedule.readiness.waivers.approve'
        WHEN NEW.event_type LIKE 'commitment_%' THEN 'schedule.readiness.commitments.publish'
        ELSE 'schedule.readiness.constraints.manage'
    END;
    IF NOT lookahead_readiness_validate_authorization_decision(
        NEW.authorization_decision,
        NEW.authorization_decision_hash,
        NEW.actor_id,
        expected_permission,
        NEW.organization_id,
        NEW.project_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness event actor mismatch' USING ERRCODE = '23514';
    END IF;

    expected_evidence_hash := lookahead_readiness_hash_json(jsonb_build_object(
        'actor_id', NEW.actor_id::text,
        'aggregate_id', NEW.aggregate_id,
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
    IF NEW.evidence_hash IS DISTINCT FROM expected_evidence_hash THEN
        RAISE EXCEPTION 'lookahead readiness event evidence hash mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.prior_event_id IS NOT NULL THEN
        SELECT * INTO prior FROM lookahead_readiness_events WHERE event_id = NEW.prior_event_id FOR KEY SHARE;
        IF NOT FOUND
           OR prior.commitment_revision_id IS DISTINCT FROM NEW.commitment_revision_id
           OR prior.commitment_task_id IS DISTINCT FROM NEW.commitment_task_id
           OR prior.aggregate_id IS DISTINCT FROM NEW.aggregate_id
           OR prior.payload->>'category' IS DISTINCT FROM NEW.payload->>'category'
           OR (prior.occurred_at, prior.event_id) >= (NEW.occurred_at, NEW.event_id) THEN
            RAISE EXCEPTION 'lookahead readiness prior event mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF (NEW.event_type IN ('constraint_registered', 'waiver_requested', 'commitment_published') AND NEW.prior_event_id IS NOT NULL)
       OR (NEW.event_type = 'constraint_evidence_attached' AND prior.event_type NOT IN ('constraint_registered', 'constraint_evidence_attached', 'constraint_reopened'))
       OR (NEW.event_type = 'constraint_resolved' AND prior.event_type NOT IN ('constraint_registered', 'constraint_evidence_attached', 'constraint_reopened'))
       OR (NEW.event_type = 'constraint_reopened' AND prior.event_type IS DISTINCT FROM 'constraint_resolved')
       OR (NEW.event_type IN ('waiver_approved', 'waiver_rejected') AND prior.event_type IS DISTINCT FROM 'waiver_requested')
       OR (NEW.event_type IN ('waiver_expired', 'waiver_revoked') AND prior.event_type IS DISTINCT FROM 'waiver_approved')
       OR (NEW.event_type IN ('commitment_superseded', 'commitment_withdrawn') AND prior.event_type IS DISTINCT FROM 'commitment_published') THEN
        RAISE EXCEPTION 'lookahead readiness transition mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type IN ('constraint_evidence_attached', 'waiver_approved') AND NEW.evidence IS NULL THEN
        RAISE EXCEPTION 'lookahead readiness evidence required' USING ERRCODE = '23514';
    END IF;

    IF NEW.commitment_task_id IS NOT NULL AND NEW.event_type <> 'readiness_evaluated' AND (
        coalesce(NEW.payload->>'category', '') = ''
        OR (policy.canonical_definition#>ARRAY[
            'task_classes', plan_task.task_class, 'required'
        ] ? (NEW.payload->>'category')) IS DISTINCT FROM true
        OR NEW.evidence IS NOT NULL AND (
            jsonb_typeof(NEW.evidence) IS DISTINCT FROM 'object'
            OR coalesce(NEW.evidence->>'locator', '') = ''
            OR coalesce(NEW.evidence->>'version', '') = ''
            OR NEW.evidence->>'hash' !~ '^[a-f0-9]{64}$'
            OR (policy.canonical_definition->'evidence_types' ? (NEW.evidence->>'type')) IS DISTINCT FROM true
            OR (policy.canonical_definition#>ARRAY[
                'task_classes', plan_task.task_class, 'required', NEW.payload->>'category', 'allowed_evidence_types'
            ] ? (NEW.evidence->>'type')) IS DISTINCT FROM true
        )
    ) THEN
        RAISE EXCEPTION 'lookahead readiness event evidence or category mismatch' USING ERRCODE = '23514';
    END IF;

    IF NEW.prior_event_id IS NULL AND NEW.event_type <> 'readiness_evaluated' AND EXISTS (
        SELECT 1 FROM lookahead_readiness_events event
        WHERE event.organization_id = NEW.organization_id
          AND event.aggregate_id = NEW.aggregate_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness aggregate root already exists' USING ERRCODE = '23514';
    END IF;

    IF NEW.prior_event_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM lookahead_readiness_events event
        WHERE event.organization_id = NEW.organization_id
          AND event.aggregate_id = NEW.aggregate_id
          AND (event.occurred_at, event.event_id) > (prior.occurred_at, prior.event_id)
    ) THEN
        RAISE EXCEPTION 'lookahead readiness prior event is not aggregate tail' USING ERRCODE = '23514';
    END IF;

    IF NEW.commitment_task_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM lookahead_readiness_snapshots snapshot
        WHERE snapshot.commitment_task_id = NEW.commitment_task_id
          AND snapshot.as_of >= NEW.occurred_at
    ) THEN
        RAISE EXCEPTION 'lookahead readiness event backdated after seal' USING ERRCODE = '23514';
    END IF;

    IF NEW.event_type = 'readiness_evaluated' THEN
        expected_evaluation := lookahead_readiness_expected_evaluation(
            NEW.commitment_revision_id,
            NEW.commitment_task_id,
            NEW.readiness_policy_version_id,
            (NEW.payload->>'as_of_utc')::timestamptz
        );
        IF NEW.payload->'consumed_event_ids' IS DISTINCT FROM expected_evaluation->'consumed_event_ids'
           OR NEW.payload->>'source_watermark' IS DISTINCT FROM expected_evaluation->>'source_watermark'
           OR NEW.payload->'component_outcomes' IS DISTINCT FROM expected_evaluation->'component_outcomes'
           OR NEW.payload->'reason_codes' IS DISTINCT FROM expected_evaluation->'reason_codes'
           OR NEW.payload->>'state' IS DISTINCT FROM expected_evaluation->>'state'
           OR (NEW.payload->>'as_of_utc')::timestamptz > NEW.occurred_at THEN
            RAISE EXCEPTION 'lookahead readiness evaluation source mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NEW.event_type = 'waiver_approved' THEN
        valid_until := (NEW.payload->>'valid_until')::timestamptz;
        IF NEW.commitment_task_id IS NULL
           OR NEW.evidence IS NULL
           OR coalesce(trim(NEW.payload->>'reason'), '') = ''
           OR (policy.canonical_definition#>'{waiver,allowed_categories}' ? (NEW.payload->>'category')) IS DISTINCT FROM true
           OR NEW.payload->>'schedule_revision_hash' IS DISTINCT FROM commitment.schedule_revision_hash
           OR valid_until <= NEW.occurred_at
           OR valid_until > NEW.occurred_at
                + make_interval(hours => (policy.canonical_definition#>>'{waiver,max_validity_hours}')::integer)
           OR NOT lookahead_readiness_user_in_scope(NEW.actor_id, NEW.organization_id, NEW.project_id) THEN
            RAISE EXCEPTION 'lookahead readiness waiver mismatch' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NEW.event_type IN ('commitment_superseded', 'commitment_withdrawn') THEN
        INSERT INTO lookahead_commitment_lifecycle_events (
            commitment_revision_id,
            sequence,
            from_state,
            to_state,
            effective_at,
            actor_id,
            idempotency_key,
            authorization_decision,
            authorization_decision_hash,
            created_at
        ) VALUES (
            NEW.commitment_revision_id,
            3,
            'published',
            CASE WHEN NEW.event_type = 'commitment_superseded' THEN 'superseded' ELSE 'withdrawn' END,
            NEW.occurred_at,
            NEW.actor_id,
            NEW.idempotency_key || ':lifecycle',
            NEW.authorization_decision,
            NEW.authorization_decision_hash,
            NEW.occurred_at
        );
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
    evaluation lookahead_readiness_events%ROWTYPE;
    previous lookahead_readiness_snapshots%ROWTYPE;
    expected_readiness_hash text;
    expected_snapshot_hash text;
    expected_evaluation jsonb;
    required jsonb;
    required_count integer;
    component_count integer;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'lookahead-task-event-stream:' || NEW.organization_id::text || ':' || NEW.commitment_task_id::text,
        0
    ));
    PERFORM pg_advisory_xact_lock(hashtextextended(
        'lookahead-snapshot-revision:' || NEW.organization_id::text || ':' || NEW.commitment_task_id::text,
        0
    ));
    SELECT * INTO commitment FROM lookahead_commitment_revisions WHERE id = NEW.commitment_revision_id FOR KEY SHARE;
    SELECT * INTO task FROM lookahead_commitment_tasks WHERE id = NEW.commitment_task_id FOR KEY SHARE;
    SELECT * INTO plan_task FROM schedule_plan_revision_tasks
    WHERE id = task.schedule_plan_revision_task_id FOR KEY SHARE;
    SELECT * INTO policy FROM lookahead_readiness_policy_versions WHERE id = NEW.readiness_policy_version_id FOR KEY SHARE;
    SELECT * INTO evaluation FROM lookahead_readiness_events WHERE event_id = NEW.evaluation_event_id FOR KEY SHARE;
    SELECT * INTO previous FROM lookahead_readiness_snapshots
    WHERE commitment_task_id = NEW.commitment_task_id
    ORDER BY snapshot_revision DESC LIMIT 1 FOR UPDATE;
    required := policy.canonical_definition#>ARRAY['task_classes', plan_task.task_class, 'required'];
    expected_evaluation := lookahead_readiness_expected_evaluation(
        NEW.commitment_revision_id,
        NEW.commitment_task_id,
        NEW.readiness_policy_version_id,
        NEW.as_of
    );
    SELECT count(*) INTO required_count FROM jsonb_object_keys(required);
    SELECT count(*) INTO component_count FROM jsonb_array_elements(NEW.component_outcomes);

    IF NEW.state NOT IN ('ready', 'blocked', 'at_risk', 'unknown', 'not_applicable')
       OR jsonb_typeof(NEW.component_outcomes) IS DISTINCT FROM 'array'
       OR jsonb_typeof(NEW.reason_codes) IS DISTINCT FROM 'array'
       OR jsonb_typeof(NEW.blocker_event_ids) IS DISTINCT FROM 'array'
       OR jsonb_typeof(NEW.waiver_event_ids) IS DISTINCT FROM 'array'
       OR commitment.id IS NULL
       OR task.id IS NULL
       OR policy.id IS NULL
       OR evaluation.event_id IS NULL
       OR task.commitment_revision_id IS DISTINCT FROM commitment.id
       OR task.id IS DISTINCT FROM NEW.commitment_task_id
       OR NEW.organization_id IS DISTINCT FROM commitment.organization_id
       OR NEW.project_id IS DISTINCT FROM commitment.project_id
       OR NEW.schedule_id IS DISTINCT FROM commitment.schedule_id
       OR NEW.organization_id IS DISTINCT FROM policy.organization_id
       OR NEW.readiness_policy_version_id IS DISTINCT FROM commitment.readiness_policy_version_id
       OR NEW.policy_hash IS DISTINCT FROM policy.policy_hash
       OR NEW.schedule_revision_hash IS DISTINCT FROM commitment.schedule_revision_hash
       OR NEW.commitment_revision_hash IS DISTINCT FROM commitment.content_hash
       OR (SELECT lifecycle.to_state FROM lookahead_commitment_lifecycle_events lifecycle
           WHERE lifecycle.commitment_revision_id = commitment.id
           ORDER BY lifecycle.sequence DESC LIMIT 1) IS DISTINCT FROM 'published'
       OR NEW.as_of < commitment.published_at
       OR NEW.calculated_at < NEW.as_of
       OR NEW.created_at IS DISTINCT FROM NEW.calculated_at
       OR NEW.command_hash !~ '^[a-f0-9]{64}$'
       OR evaluation.event_type IS DISTINCT FROM 'readiness_evaluated'
       OR evaluation.commitment_revision_id IS DISTINCT FROM NEW.commitment_revision_id
       OR evaluation.commitment_task_id IS DISTINCT FROM NEW.commitment_task_id
       OR evaluation.readiness_policy_version_id IS DISTINCT FROM NEW.readiness_policy_version_id
       OR evaluation.actor_id IS DISTINCT FROM NEW.sealed_by_actor_id
       OR evaluation.authorization_decision_hash IS DISTINCT FROM NEW.authorization_decision_hash
       OR evaluation.idempotency_key IS DISTINCT FROM NEW.idempotency_key || ':readiness-evaluated'
       OR evaluation.payload->>'as_of_utc' IS DISTINCT FROM to_char(NEW.as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"')
       OR evaluation.payload->>'state' IS DISTINCT FROM NEW.state
       OR evaluation.payload->'component_outcomes' IS DISTINCT FROM NEW.component_outcomes
       OR evaluation.payload->'reason_codes' IS DISTINCT FROM NEW.reason_codes
       OR evaluation.payload->>'source_watermark' IS DISTINCT FROM NEW.source_watermark
       OR NEW.blocker_event_ids IS DISTINCT FROM expected_evaluation->'blocker_event_ids'
       OR NEW.waiver_event_ids IS DISTINCT FROM expected_evaluation->'waiver_event_ids'
       OR EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(NEW.blocker_event_ids) AS event_ref
            WHERE NOT EXISTS (
                SELECT 1 FROM lookahead_readiness_events
                WHERE event_id::text = event_ref
                  AND commitment_revision_id = NEW.commitment_revision_id
                  AND commitment_task_id = NEW.commitment_task_id
                  AND event_type IN ('constraint_registered', 'constraint_evidence_attached', 'constraint_reopened')
                  AND evaluation.payload->'consumed_event_ids' ? event_ref
            )
       )
       OR EXISTS (
            SELECT 1 FROM jsonb_array_elements_text(NEW.waiver_event_ids) AS event_ref
            WHERE NOT EXISTS (
                SELECT 1 FROM lookahead_readiness_events
                WHERE event_id::text = event_ref
                  AND commitment_revision_id = NEW.commitment_revision_id
                  AND commitment_task_id = NEW.commitment_task_id
                  AND event_type = 'waiver_approved'
                  AND (payload->>'valid_until')::timestamptz > NEW.as_of
                  AND payload->>'schedule_revision_hash' = NEW.schedule_revision_hash
                  AND authorization_decision->>'permission' = 'schedule.readiness.waivers.approve'
                  AND evaluation.payload->'consumed_event_ids' ? event_ref
            )
       ) THEN
        RAISE EXCEPTION 'lookahead readiness snapshot mismatch' USING ERRCODE = '23514';
    END IF;

    IF (previous.id IS NULL AND (NEW.snapshot_revision IS DISTINCT FROM 1 OR NEW.predecessor_snapshot_id IS NOT NULL))
       OR (previous.id IS NOT NULL AND (
            NEW.snapshot_revision IS DISTINCT FROM previous.snapshot_revision + 1
            OR NEW.predecessor_snapshot_id IS DISTINCT FROM previous.id
            OR NEW.as_of < previous.as_of
            OR NEW.calculated_at < previous.calculated_at
       )) THEN
        RAISE EXCEPTION 'lookahead readiness snapshot predecessor mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT lookahead_readiness_validate_authorization_decision(
        NEW.authorization_decision,
        NEW.authorization_decision_hash,
        NEW.sealed_by_actor_id,
        'schedule.readiness.evaluations.seal',
        NEW.organization_id,
        NEW.project_id
    ) THEN
        RAISE EXCEPTION 'lookahead readiness snapshot authorization mismatch' USING ERRCODE = '23514';
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
        'as_of_utc', to_char(NEW.as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'authorization_decision_hash', NEW.authorization_decision_hash,
        'blocker_event_ids', NEW.blocker_event_ids,
        'calculated_at_utc', to_char(NEW.calculated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'commitment_revision_hash', NEW.commitment_revision_hash,
        'commitment_revision_id', NEW.commitment_revision_id::text,
        'commitment_task_id', NEW.commitment_task_id::text,
        'component_outcomes', NEW.component_outcomes,
        'evaluation_event_id', NEW.evaluation_event_id::text,
        'organization_id', NEW.organization_id::text,
        'policy_hash', NEW.policy_hash,
        'project_id', NEW.project_id::text,
        'reason_codes', NEW.reason_codes,
        'schedule_id', NEW.schedule_id::text,
        'schedule_revision_hash', NEW.schedule_revision_hash,
        'sealed_by_actor_id', NEW.sealed_by_actor_id::text,
        'snapshot_revision', NEW.snapshot_revision,
        'source_watermark', NEW.source_watermark,
        'state', NEW.state,
        'waiver_event_ids', NEW.waiver_event_ids
    ));
    expected_snapshot_hash := lookahead_readiness_hash_json(jsonb_build_object(
        'as_of_utc', to_char(NEW.as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'authorization_decision_hash', NEW.authorization_decision_hash,
        'blocker_event_ids', NEW.blocker_event_ids,
        'calculated_at_utc', to_char(NEW.calculated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'commitment_revision_hash', NEW.commitment_revision_hash,
        'commitment_revision_id', NEW.commitment_revision_id::text,
        'commitment_task_id', NEW.commitment_task_id::text,
        'component_outcomes', NEW.component_outcomes,
        'evaluation_event_id', NEW.evaluation_event_id::text,
        'organization_id', NEW.organization_id::text,
        'policy_hash', NEW.policy_hash,
        'project_id', NEW.project_id::text,
        'reason_codes', NEW.reason_codes,
        'schedule_id', NEW.schedule_id::text,
        'schedule_revision_hash', NEW.schedule_revision_hash,
        'sealed_by_actor_id', NEW.sealed_by_actor_id::text,
        'snapshot_revision', NEW.snapshot_revision,
        'source_watermark', NEW.source_watermark,
        'state', NEW.state,
        'waiver_event_ids', NEW.waiver_event_ids,
        'actual_comparison', NEW.actual_comparison,
        'readiness_hash', expected_readiness_hash
    ));
    IF NEW.readiness_hash IS DISTINCT FROM expected_readiness_hash
       OR NEW.snapshot_hash IS DISTINCT FROM expected_snapshot_hash THEN
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
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_expected_evaluation(bigint, bigint, bigint, timestamptz) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_effectivity() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_lifecycle() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_completeness() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment_task() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_commitment() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_completeness() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_effectivity() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_lifecycle() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_dependency() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_task() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_schedule_revision() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_policy() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_policy_definition_valid(jsonb) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_validate_authorization_decision(jsonb, text, bigint, text, bigint, bigint) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_lock_schedule_source() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_reject_mutation() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_user_in_scope(bigint, bigint, bigint) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_hash_json(jsonb) CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS lookahead_readiness_canonical_json(jsonb) CASCADE');
        }

        Schema::dropIfExists('lookahead_readiness_snapshots');
        Schema::dropIfExists('lookahead_readiness_events');
        Schema::dropIfExists('lookahead_commitment_tasks');
        Schema::dropIfExists('lookahead_commitment_lifecycle_events');
        Schema::dropIfExists('lookahead_commitment_revisions');
        Schema::dropIfExists('schedule_plan_revision_dependencies');
        Schema::dropIfExists('schedule_plan_revision_tasks');
        Schema::dropIfExists('schedule_plan_revision_lifecycle_events');
        Schema::dropIfExists('schedule_plan_revisions');
        Schema::dropIfExists('lookahead_readiness_policy_versions');
        Schema::dropIfExists('lookahead_readiness_system_role_definitions');
    }
};
