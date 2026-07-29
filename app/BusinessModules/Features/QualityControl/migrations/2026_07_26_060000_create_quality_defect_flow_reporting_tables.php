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

        Schema::create('quality_defect_flow_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('version', 80);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->jsonb('terminal_statuses');
            $table->unsignedSmallInteger('maturity_days');
            $table->unsignedSmallInteger('sla_days');
            $table->string('calendar_code', 80);
            $table->boolean('closure_evidence_required')->default(true);
            $table->jsonb('severity_weights');
            $table->char('source_hash', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['organization_id', 'project_id', 'version'], 'quality_defect_flow_policy_version_unique');
            $table->index(['organization_id', 'project_id', 'effective_from', 'effective_until'], 'quality_defect_flow_policy_effective_idx');
        });

        Schema::create('quality_defect_transition_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('schedule_task_id')->nullable();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('status_history_id')->constrained('quality_defect_status_history')->restrictOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('severity', 32);
            $table->date('due_date')->nullable();
            $table->timestampTz('occurred_at');
            $table->unsignedInteger('event_version');
            $table->jsonb('evidence_refs');
            $table->char('event_hash', 64);
            $table->timestampTz('recorded_at');

            $table->unique(['organization_id', 'quality_defect_id', 'event_version'], 'quality_defect_transition_version_unique');
            $table->unique(['organization_id', 'status_history_id'], 'quality_defect_transition_history_unique');
            $table->index(['organization_id', 'project_id', 'occurred_at', 'quality_defect_id', 'id'], 'quality_defect_transition_order_idx');
        });

        Schema::create('quality_defect_flow_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->jsonb('policy_version_ids');
            $table->char('scope_hash', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 80);
            $table->char('query_hash', 64);
            $table->char('input_hash', 64);
            $table->char('output_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('as_of');
            $table->timestampTz('source_watermark');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('opening_count')->default(0);
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('reopened_count')->default(0);
            $table->unsignedBigInteger('closed_count')->default(0);
            $table->unsignedBigInteger('closing_count')->default(0);
            $table->unsignedBigInteger('due_count')->default(0);
            $table->unsignedBigInteger('overdue_count')->default(0);
            $table->decimal('overdue_pct', 7, 4)->nullable();
            $table->unsignedBigInteger('mature_cohort_count')->default(0);
            $table->unsignedBigInteger('first_pass_count')->default(0);
            $table->unsignedBigInteger('mature_reopened_count')->default(0);
            $table->decimal('reopen_rate', 7, 4)->nullable();
            $table->decimal('first_pass_yield', 7, 4)->nullable();
            $table->unsignedBigInteger('eligible_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at');

            $table->unique(['organization_id', 'scope_hash', 'as_of', 'formula_version', 'source_hash'], 'quality_defect_flow_snapshot_unique');
            $table->index(['organization_id', 'project_id', 'as_of'], 'quality_defect_flow_snapshot_scope_idx');
        });

        Schema::create('quality_defect_flow_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('quality_defect_flow_snapshots')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('schedule_task_id')->nullable();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->restrictOnDelete();
            $table->unsignedInteger('event_version');
            $table->string('row_key', 190);
            $table->date('cohort_date');
            $table->string('severity', 32);
            $table->string('status', 40);
            $table->boolean('opening_flag');
            $table->boolean('created_flag');
            $table->boolean('reopened_flag');
            $table->boolean('closed_flag');
            $table->boolean('closing_flag');
            $table->boolean('cohort_eligible');
            $table->unsignedInteger('cycle_days')->nullable();
            $table->date('due_date')->nullable();
            $table->jsonb('evidence_refs');

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'quality_defect_flow_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'contractor_id', 'severity', 'status', 'cohort_date', 'row_key'],
                'quality_defect_flow_rows_filter_idx'
            );
            $table->index(['organization_id', 'snapshot_id', 'cohort_date', 'row_key'], 'quality_defect_flow_rows_sort_idx');
        });

        DB::statement('ALTER TABLE quality_defect_flow_policy_versions ADD CONSTRAINT quality_defect_flow_policy_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE quality_defect_flow_policy_versions ADD CONSTRAINT quality_defect_flow_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE quality_defect_flow_policy_versions ADD CONSTRAINT quality_defect_flow_policy_terminal_statuses_check CHECK (jsonb_typeof(terminal_statuses) = 'array' AND jsonb_array_length(terminal_statuses) > 0)");
        DB::statement("ALTER TABLE quality_defect_flow_policy_versions ADD CONSTRAINT quality_defect_flow_policy_calendar_check CHECK (calendar_code = 'calendar_days')");
        DB::statement('CREATE UNIQUE INDEX quality_defect_flow_policy_scope_version_unique ON quality_defect_flow_policy_versions (organization_id, COALESCE(project_id, 0), version)');
        DB::statement("ALTER TABLE quality_defect_flow_policy_versions ADD CONSTRAINT quality_defect_flow_policy_no_overlap EXCLUDE USING gist (organization_id WITH =, (COALESCE(project_id, 0)) WITH =, (daterange(effective_from, COALESCE(effective_until, 'infinity'::date), '[]')) WITH &&)");
        DB::statement('ALTER TABLE quality_defect_transition_events ADD CONSTRAINT quality_defect_transition_event_version_check CHECK (event_version > 0)');
        DB::statement("ALTER TABLE quality_defect_transition_events ADD CONSTRAINT quality_defect_transition_event_hash_check CHECK (event_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_hashes_check CHECK (scope_hash ~ '^[a-f0-9]{64}$' AND definition_hash ~ '^[a-f0-9]{64}$' AND query_hash ~ '^[a-f0-9]{64}$' AND input_hash ~ '^[a-f0-9]{64}$' AND output_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_counts_check CHECK (projected_count <= eligible_count AND row_count = projected_count AND closing_count = opening_count + created_count + reopened_count - closed_count)');
        DB::statement('ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_due_check CHECK (overdue_count <= due_count AND ((due_count = 0 AND overdue_pct IS NULL) OR (due_count > 0 AND overdue_pct BETWEEN 0 AND 100)))');
        DB::statement('ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_mature_check CHECK (first_pass_count <= mature_cohort_count AND mature_reopened_count <= mature_cohort_count AND ((mature_cohort_count = 0 AND first_pass_yield IS NULL AND reopen_rate IS NULL) OR (mature_cohort_count > 0 AND first_pass_yield BETWEEN 0 AND 100 AND reopen_rate BETWEEN 0 AND 100)))');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION quality_defect_reporting_immutable_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'quality_defect_reporting_record_immutable' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER quality_defect_transition_events_immutable
BEFORE UPDATE OR DELETE ON quality_defect_transition_events
FOR EACH ROW EXECUTE FUNCTION quality_defect_reporting_immutable_guard();
CREATE TRIGGER quality_defect_flow_policies_immutable
BEFORE UPDATE OR DELETE ON quality_defect_flow_policy_versions
FOR EACH ROW EXECUTE FUNCTION quality_defect_reporting_immutable_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defect_flow_rows');
        Schema::dropIfExists('quality_defect_flow_snapshots');
        Schema::dropIfExists('quality_defect_transition_events');
        Schema::dropIfExists('quality_defect_flow_policy_versions');
        DB::statement('DROP FUNCTION IF EXISTS quality_defect_reporting_immutable_guard()');
    }
};
