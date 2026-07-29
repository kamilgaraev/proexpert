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
        Schema::create('safety_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('timezone', 80);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'project_id', 'code'], 'safety_site_code_unique');
            $table->index(['organization_id', 'project_id', 'is_active'], 'safety_site_scope_idx');
        });

        Schema::create('safety_incident_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('version', 80);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->jsonb('qualifying_incident_types');
            $table->jsonb('terminal_statuses');
            $table->boolean('closure_evidence_required')->default(true);
            $table->string('overdue_rule', 80);
            $table->string('calendar_code', 80);
            $table->unsignedInteger('frequency_multiplier');
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->unique(['organization_id', 'project_id', 'version'], 'safety_incident_policy_version_unique');
            $table->index(['organization_id', 'project_id', 'effective_from', 'effective_until'], 'safety_incident_policy_effective_idx');
        });

        Schema::create('safety_transition_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 80)->nullable();
            $table->string('severity', 32);
            $table->date('due_date')->nullable();
            $table->timestampTz('occurred_at');
            $table->unsignedInteger('event_version');
            $table->string('evidence_type', 40)->nullable();
            $table->string('evidence_id', 120)->nullable();
            $table->char('event_hash', 64);
            $table->timestampTz('recorded_at');

            $table->unique(['organization_id', 'subject_type', 'subject_id', 'event_version'], 'safety_transition_event_version_unique');
            $table->index(['organization_id', 'project_id', 'occurred_at', 'subject_type', 'subject_id', 'id'], 'safety_transition_event_order_idx');
            $table->index(['organization_id', 'safety_site_id', 'occurred_at', 'subject_type'], 'safety_transition_site_idx');
        });

        Schema::create('safety_exposure_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->constrained('safety_sites')->restrictOnDelete();
            $table->date('exposure_date');
            $table->decimal('exposure_hours', 18, 4);
            $table->unsignedInteger('person_shifts');
            $table->string('source_code', 80);
            $table->string('source_watermark', 120);
            $table->boolean('complete');
            $table->char('source_hash', 64);
            $table->timestampTz('projected_at');

            $table->unique(['organization_id', 'safety_site_id', 'exposure_date'], 'safety_exposure_day_unique');
            $table->index(['organization_id', 'project_id', 'exposure_date', 'complete'], 'safety_exposure_scope_idx');
        });

        Schema::create('safety_incident_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('safety_incident_policy_versions')->restrictOnDelete();
            $table->char('scope_hash', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 80);
            $table->char('source_hash', 64);
            $table->timestampTz('as_of');
            $table->timestampTz('source_watermark');
            $table->unsignedBigInteger('row_count')->default(0);
            $table->unsignedBigInteger('incident_count')->default(0);
            $table->unsignedBigInteger('violation_count')->default(0);
            $table->unsignedBigInteger('action_due_count')->default(0);
            $table->unsignedBigInteger('action_overdue_count')->default(0);
            $table->unsignedBigInteger('action_closed_on_time_count')->default(0);
            $table->decimal('exposure_hours', 18, 4)->nullable();
            $table->boolean('exposure_complete');
            $table->decimal('incident_frequency', 18, 4)->nullable();
            $table->unsignedBigInteger('eligible_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at');

            $table->unique(['organization_id', 'scope_hash', 'as_of', 'formula_version', 'source_hash'], 'safety_incident_snapshot_unique');
            $table->index(['organization_id', 'project_id', 'as_of'], 'safety_incident_snapshot_scope_idx');
        });

        Schema::create('safety_incident_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->ulid('snapshot_id');
            $table->foreign('snapshot_id')->references('id')->on('safety_incident_snapshots')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('safety_site_id')->nullable()->constrained('safety_sites')->restrictOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('event_version');
            $table->string('row_key', 190);
            $table->date('event_date');
            $table->string('category', 80)->nullable();
            $table->string('severity', 32);
            $table->string('status', 40);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->boolean('opening_flag');
            $table->boolean('created_flag');
            $table->boolean('reopened_flag');
            $table->boolean('closed_flag');
            $table->boolean('closing_flag');
            $table->boolean('closure_verified');
            $table->unsignedInteger('closure_days')->nullable();
            $table->string('evidence_type', 40)->nullable();
            $table->string('evidence_id', 120)->nullable();

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'safety_incident_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'safety_site_id', 'severity', 'status', 'due_date', 'row_key'],
                'safety_incident_rows_filter_idx'
            );
            $table->index(['organization_id', 'snapshot_id', 'event_date', 'row_key'], 'safety_incident_rows_sort_idx');
        });

        DB::statement('ALTER TABLE safety_incident_policy_versions ADD CONSTRAINT safety_incident_policy_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE safety_incident_policy_versions ADD CONSTRAINT safety_incident_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_transition_events ADD CONSTRAINT safety_transition_subject_check CHECK (subject_type IN ('incident', 'violation', 'corrective_action'))");
        DB::statement('ALTER TABLE safety_transition_events ADD CONSTRAINT safety_transition_event_version_check CHECK (event_version > 0)');
        DB::statement("ALTER TABLE safety_transition_events ADD CONSTRAINT safety_transition_event_hash_check CHECK (event_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE safety_exposure_days ADD CONSTRAINT safety_exposure_non_negative_check CHECK (exposure_hours >= 0 AND person_shifts >= 0)');
        DB::statement("ALTER TABLE safety_exposure_days ADD CONSTRAINT safety_exposure_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE safety_incident_snapshots ADD CONSTRAINT safety_incident_snapshot_hashes_check CHECK (scope_hash ~ '^[a-f0-9]{64}$' AND definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE safety_incident_snapshots ADD CONSTRAINT safety_incident_snapshot_counts_check CHECK (projected_count <= eligible_count AND row_count = projected_count)');
        DB::statement('ALTER TABLE safety_incident_snapshots ADD CONSTRAINT safety_incident_snapshot_frequency_check CHECK ((exposure_complete AND exposure_hours IS NOT NULL AND ((exposure_hours = 0 AND incident_frequency IS NULL) OR (exposure_hours > 0 AND incident_frequency IS NOT NULL))) OR (NOT exposure_complete AND exposure_hours IS NULL AND incident_frequency IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_incident_rows');
        Schema::dropIfExists('safety_incident_snapshots');
        Schema::dropIfExists('safety_exposure_days');
        Schema::dropIfExists('safety_transition_events');
        Schema::dropIfExists('safety_incident_policy_versions');
        Schema::dropIfExists('safety_sites');
    }
};
