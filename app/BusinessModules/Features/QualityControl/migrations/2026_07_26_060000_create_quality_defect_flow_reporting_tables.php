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
        Schema::create('quality_defect_flow_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
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
            $table->timestampsTz();

            $table->unique(['organization_id', 'project_id', 'version'], 'quality_defect_flow_policy_version_unique');
            $table->index(['organization_id', 'project_id', 'effective_from', 'effective_until'], 'quality_defect_flow_policy_effective_idx');
        });

        Schema::create('quality_defect_transition_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('schedule_task_id')->nullable();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_history_id')->nullable()->constrained('quality_defect_status_history')->restrictOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('severity', 32);
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
            $table->foreignId('policy_version_id')->constrained('quality_defect_flow_policy_versions')->restrictOnDelete();
            $table->char('scope_hash', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 80);
            $table->char('source_hash', 64);
            $table->timestampTz('as_of');
            $table->timestampTz('source_watermark');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('opening_count')->default(0);
            $table->unsignedBigInteger('created_count')->default(0);
            $table->unsignedBigInteger('reopened_count')->default(0);
            $table->unsignedBigInteger('closed_count')->default(0);
            $table->unsignedBigInteger('closing_count')->default(0);
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
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
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
        DB::statement('ALTER TABLE quality_defect_transition_events ADD CONSTRAINT quality_defect_transition_event_version_check CHECK (event_version > 0)');
        DB::statement("ALTER TABLE quality_defect_transition_events ADD CONSTRAINT quality_defect_transition_event_hash_check CHECK (event_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_hashes_check CHECK (scope_hash ~ '^[a-f0-9]{64}$' AND definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE quality_defect_flow_snapshots ADD CONSTRAINT quality_defect_flow_snapshot_counts_check CHECK (projected_count <= eligible_count AND row_count = projected_count AND closing_count = opening_count + created_count + reopened_count - closed_count)');
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defect_flow_rows');
        Schema::dropIfExists('quality_defect_flow_snapshots');
        Schema::dropIfExists('quality_defect_transition_events');
        Schema::dropIfExists('quality_defect_flow_policy_versions');
    }
};
