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
        Schema::create('lookahead_reporting_policy_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('version');
            $table->unsignedInteger('horizon_days');
            $table->jsonb('eligible_task_statuses');
            $table->jsonb('mandatory_constraint_types');
            $table->jsonb('hard_severities');
            $table->boolean('waiver_evidence_required');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->string('timezone', 64);
            $table->char('source_hash', 64);
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'effective_from', 'effective_until', 'version'], 'lookahead_policy_effective');
        });
        DB::statement(
            'CREATE UNIQUE INDEX lookahead_policy_version_unique
            ON lookahead_reporting_policy_versions (
                organization_id,
                COALESCE(project_id, 0),
                version
            )'
        );
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lookahead_reporting_history_append_only_guard()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'lookahead_reporting_history_is_append_only' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER lookahead_policy_versions_append_only
BEFORE UPDATE OR DELETE ON lookahead_reporting_policy_versions
FOR EACH ROW EXECUTE FUNCTION lookahead_reporting_history_append_only_guard();
SQL);

        Schema::create('lookahead_reporting_constraint_transition_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('constraint_id');
            $table->unsignedInteger('event_version');
            $table->string('source_event_id', 128);
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64);
            $table->string('constraint_type', 64);
            $table->string('severity', 64);
            $table->timestampTz('waiver_until')->nullable();
            $table->string('waiver_evidence_ref')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->char('source_hash', 64);
            $table->jsonb('evidence_refs');
            $table->unique(['organization_id', 'constraint_id', 'event_version'], 'constraint_event_version_unique');
            $table->unique(['organization_id', 'source_event_id'], 'constraint_source_event_unique');
            $table->index(['organization_id', 'project_id', 'occurred_at', 'id'], 'constraint_event_order');
        });
        DB::statement(
            'CREATE TRIGGER lookahead_reporting_constraint_transition_events_append_only
            BEFORE UPDATE OR DELETE ON lookahead_reporting_constraint_transition_events
            FOR EACH ROW EXECUTE FUNCTION lookahead_reporting_history_append_only_guard()'
        );

        Schema::create('lookahead_reporting_snapshots', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->jsonb('policy_version_ids');
            $table->date('as_of');
            $table->string('formula_version', 64);
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->jsonb('watermarks');
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->jsonb('row_schema');
            $table->unsignedInteger('row_count');
            $table->timestampsTz();
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'lookahead_snapshot_unique');
        });

        Schema::create('lookahead_reporting_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('snapshot_id', 26);
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('constraint_id')->nullable();
            $table->string('constraint_type', 64)->nullable();
            $table->string('constraint_status', 64)->nullable();
            $table->date('planned_start_date');
            $table->string('wbs_code')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('severity', 64)->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('eligible');
            $table->boolean('ready');
            $table->integer('age_days');
            $table->jsonb('payload');
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'lookahead_row_unique');
            $table->index(['organization_id', 'snapshot_id', 'project_id', 'ready', 'severity', 'due_date', 'row_key'], 'lookahead_row_keyset');
            $table->index(['organization_id', 'snapshot_id', 'constraint_type', 'constraint_status', 'row_key'], 'lookahead_row_constraints');
            $table->index(
                ['organization_id', 'snapshot_id', 'wbs_code', 'owner_id', 'contractor_id', 'zone_id', 'row_key'],
                'lookahead_row_filters'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookahead_reporting_rows');
        Schema::dropIfExists('lookahead_reporting_snapshots');
        Schema::dropIfExists('lookahead_reporting_constraint_transition_events');
        Schema::dropIfExists('lookahead_reporting_policy_versions');
        DB::statement('DROP FUNCTION IF EXISTS lookahead_reporting_history_append_only_guard()');
    }
};
