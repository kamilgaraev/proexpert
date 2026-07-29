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
        Schema::table('acceptance_checklist_items', function (Blueprint $table): void {
            $table->string('code', 64)->nullable()->after('acceptance_checklist_id');
            $table->timestampTz('reviewed_at', 6)->nullable()->after('status');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->after('reviewed_at');
            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->unique(['acceptance_checklist_id', 'code'], 'acceptance_checklist_item_code_unique');
        });
        Schema::table('handover_package_documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approved_at');
            $table->foreign('approved_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::create('handover_gate_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('acceptance_scope_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('gate_code', 64);
            $table->unsignedInteger('gate_version');
            $table->jsonb('required_checklist_codes');
            $table->jsonb('required_document_codes');
            $table->jsonb('hard_blocker_source_types');
            $table->boolean('explicitly_empty_requirements')->default(false);
            $table->jsonb('due_policy');
            $table->char('source_hash', 64);
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('effective_to', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(
                ['organization_id', 'acceptance_scope_id', 'gate_version'],
                'handover_gate_scope_version_unique',
            );
            $table->index(
                ['organization_id', 'project_id', 'location_id', 'package_id', 'effective_from', 'gate_version'],
                'handover_gate_effective_idx',
            );
        });

        Schema::create('handover_evidence_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('acceptance_scope_id');
            $table->string('event_type', 64);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_version');
            $table->string('source_code', 64)->nullable();
            $table->string('status', 64);
            $table->unsignedBigInteger('causation_event_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->char('evidence_hash', 64);
            $table->jsonb('evidence');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version'],
                'handover_evidence_source_version_unique',
            );
            $table->index(
                ['organization_id', 'acceptance_scope_id', 'occurred_at', 'source_type', 'source_id', 'id'],
                'handover_evidence_scope_timeline_idx',
            );
            $table->foreign('causation_event_id')
                ->references('id')
                ->on('handover_evidence_events')
                ->restrictOnDelete();
        });

        Schema::create('handover_readiness_snapshots', function (Blueprint $table): void {
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

            $table->unique(['organization_id', 'source_hash', 'definition_hash'], 'handover_readiness_snapshot_identity_unique');
            $table->index(['organization_id', 'generated_at', 'id'], 'handover_readiness_snapshot_generated_idx');
        });

        Schema::create('handover_readiness_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('acceptance_scope_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('gate_code', 64);
            $table->date('due_on')->nullable();
            $table->decimal('mandatory_completeness', 12, 8);
            $table->decimal('document_completeness', 12, 8);
            $table->unsignedInteger('open_hard_blocker_count');
            $table->unsignedInteger('attempt_count');
            $table->unsignedInteger('successful_result_count');
            $table->boolean('ready');
            $table->jsonb('evidence_refs');
            $table->string('row_key', 256);

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'handover_readiness_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'location_id', 'package_id', 'gate_code', 'due_on', 'row_key'],
                'handover_readiness_row_keyset_idx',
            );
        });

        foreach ([
            'ALTER TABLE handover_gate_versions ADD CONSTRAINT handover_gate_interval_check CHECK (effective_to IS NULL OR effective_to > effective_from)',
            "ALTER TABLE handover_gate_versions ADD CONSTRAINT handover_gate_payload_check CHECK (jsonb_typeof(required_checklist_codes) = 'array' AND jsonb_typeof(required_document_codes) = 'array' AND jsonb_typeof(hard_blocker_source_types) = 'array' AND jsonb_typeof(due_policy) = 'object')",
            'ALTER TABLE handover_gate_versions ADD CONSTRAINT handover_gate_empty_requirement_check CHECK (explicitly_empty_requirements = (jsonb_array_length(required_checklist_codes) = 0 AND jsonb_array_length(required_document_codes) = 0))',
            "ALTER TABLE handover_gate_versions ADD CONSTRAINT handover_gate_hard_blocker_check CHECK (hard_blocker_source_types @> '[\"rfi\",\"change\",\"quality_defect\",\"constraint\"]'::jsonb)",
            "ALTER TABLE handover_gate_versions ADD CONSTRAINT handover_gate_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE handover_evidence_events ADD CONSTRAINT handover_evidence_hash_check CHECK (evidence_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE handover_evidence_events ADD CONSTRAINT handover_evidence_causation_check CHECK ((event_type IN ('finding_resolved','blocker_resolved','inspection_resulted')) = (causation_event_id IS NOT NULL))",
            "ALTER TABLE handover_readiness_snapshots ADD CONSTRAINT handover_readiness_snapshot_hash_check CHECK (definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE handover_readiness_snapshots ADD CONSTRAINT handover_readiness_snapshot_time_check CHECK (stale_at IS NULL OR stale_at >= generated_at)',
            'ALTER TABLE handover_readiness_rows ADD CONSTRAINT handover_readiness_ratio_check CHECK (mandatory_completeness BETWEEN 0 AND 1 AND document_completeness BETWEEN 0 AND 1)',
            'ALTER TABLE handover_readiness_rows ADD CONSTRAINT handover_readiness_result_count_check CHECK (successful_result_count <= attempt_count)',
            'ALTER TABLE handover_readiness_rows ADD CONSTRAINT handover_readiness_ready_check CHECK (NOT ready OR (mandatory_completeness = 1 AND document_completeness = 1 AND open_hard_blocker_count = 0 AND successful_result_count = attempt_count))',
        ] as $statement) {
            DB::statement($statement);
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_prevent_reporting_mutation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'reporting_fact_is_immutable';
            END;
            $$
            SQL);

        foreach ([
            'handover_gate_versions',
            'handover_evidence_events',
            'handover_readiness_snapshots',
            'handover_readiness_rows',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_reporting_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_readiness_rows');
        Schema::dropIfExists('handover_readiness_snapshots');
        Schema::dropIfExists('handover_evidence_events');
        Schema::dropIfExists('handover_gate_versions');
        Schema::table('handover_package_documents', function (Blueprint $table): void {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropColumn('approved_by_user_id');
        });
        Schema::table('acceptance_checklist_items', function (Blueprint $table): void {
            $table->dropUnique('acceptance_checklist_item_code_unique');
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropColumn(['code', 'reviewed_at', 'reviewed_by_user_id']);
        });
    }
};
