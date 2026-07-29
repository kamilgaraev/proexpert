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
        Schema::create('customer_workflow_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('workflow_type', 16);
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('source_version');
            $table->string('event_type', 32);
            $table->string('prior_status', 40)->nullable();
            $table->string('current_status', 40);
            $table->string('actor_side', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('priority', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->char('idempotency_key_hash', 64);
            $table->char('evidence_hash', 64);
            $table->jsonb('evidence');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['organization_id', 'workflow_type', 'workflow_id', 'source_version', 'event_type'],
                'customer_workflow_event_source_unique',
            );
            $table->unique(['organization_id', 'idempotency_key_hash'], 'customer_workflow_event_idempotency_unique');
            $table->index(
                ['organization_id', 'project_id', 'customer_organization_id', 'occurred_at', 'workflow_type', 'workflow_id', 'id'],
                'customer_workflow_event_timeline_idx',
            );
        });

        Schema::create('customer_sla_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->string('workflow_type', 16)->nullable();
            $table->string('priority', 32)->nullable();
            $table->string('timezone', 64);
            $table->jsonb('weekday_intervals');
            $table->jsonb('holidays');
            $table->jsonb('pause_statuses');
            $table->unsignedInteger('first_response_target_seconds');
            $table->unsignedInteger('resolution_target_seconds');
            $table->string('version', 64);
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('effective_to', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['organization_id', 'version'], 'customer_sla_policy_org_version_unique');
            $table->index(
                ['organization_id', 'project_id', 'customer_organization_id', 'effective_from', 'version'],
                'customer_sla_policy_effective_idx',
            );
        });

        Schema::create('customer_sla_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 64);
            $table->jsonb('scope_identity');
            $table->jsonb('filters');
            $table->timestampTz('as_of', 6);
            $table->timestampTz('generated_at', 6);
            $table->timestampTz('stale_at', 6)->nullable();
            $table->jsonb('watermarks');
            $table->unsignedBigInteger('row_count')->default(0);

            $table->unique(['organization_id', 'source_hash', 'definition_hash'], 'customer_sla_snapshot_identity_unique');
            $table->index(['organization_id', 'generated_at', 'id'], 'customer_sla_snapshot_generated_idx');
        });

        Schema::create('customer_sla_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->string('workflow_type', 16);
            $table->unsignedBigInteger('workflow_id');
            $table->string('priority', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('status', 40);
            $table->timestampTz('opened_at', 6);
            $table->unsignedBigInteger('first_response_seconds')->nullable();
            $table->unsignedBigInteger('resolution_seconds')->nullable();
            $table->unsignedBigInteger('open_aging_seconds')->nullable();
            $table->boolean('first_response_breached')->nullable();
            $table->boolean('resolution_breached')->nullable();
            $table->boolean('actor_side_complete');
            $table->jsonb('event_refs');
            $table->string('row_key', 256);

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'customer_sla_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'opened_at', 'project_id', 'customer_organization_id', 'workflow_type', 'workflow_id', 'row_key'],
                'customer_sla_row_keyset_idx',
            );
        });

        foreach ([
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_event_type_check CHECK (workflow_type IN ('issue','request'))",
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_actor_side_check CHECK (actor_side IN ('customer','delivery_team','system','unknown'))",
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_event_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$' AND evidence_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE customer_sla_policy_versions ADD CONSTRAINT customer_sla_policy_interval_check CHECK (effective_to IS NULL OR effective_to > effective_from)",
            "ALTER TABLE customer_sla_policy_versions ADD CONSTRAINT customer_sla_policy_targets_check CHECK (first_response_target_seconds > 0 AND resolution_target_seconds > 0)",
            "ALTER TABLE customer_sla_snapshots ADD CONSTRAINT customer_sla_snapshot_hash_check CHECK (definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE customer_sla_snapshots ADD CONSTRAINT customer_sla_snapshot_time_check CHECK (stale_at IS NULL OR stale_at >= generated_at)",
            "ALTER TABLE customer_sla_rows ADD CONSTRAINT customer_sla_row_terminal_check CHECK ((resolution_seconds IS NULL AND open_aging_seconds IS NOT NULL) OR (resolution_seconds IS NOT NULL AND open_aging_seconds IS NULL))",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sla_rows');
        Schema::dropIfExists('customer_sla_snapshots');
        Schema::dropIfExists('customer_sla_policy_versions');
        Schema::dropIfExists('customer_workflow_events');
    }
};
