<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('estimate_generation_project_model_evidence_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('assertion_id')->nullable()->after('entity_id');
            $table->unsignedBigInteger('correction_id')->nullable()->after('assertion_id');
            $table->string('candidate_source', 32)->nullable()->after('evidence_id');
            $table->char('candidate_value_fingerprint', 64)->nullable()->after('candidate_source');
            $table->index(['assertion_id', 'building_model_id'], 'eg_project_model_evidence_assertion_idx');
            $table->index(['correction_id', 'building_model_id'], 'eg_project_model_evidence_correction_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_project_model_evidence_bindings', function (Blueprint $table): void {
                $table->unique(['assertion_id', 'correction_id', 'evidence_id'], 'eg_project_model_evidence_candidate_binding_uq');
                $table->foreign(['assertion_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_assertion_scope_fk')
                    ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_assertions')
                    ->cascadeOnDelete();
                $table->foreign(['correction_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_correction_scope_fk')
                    ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_corrections')
                    ->cascadeOnDelete();
            });

            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $runtime->ensureConcurrentIndex(
                'eg_project_model_evidence_candidate_binding_uq',
                'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_evidence_candidate_binding_uq ON estimate_generation_project_model_evidence_bindings (COALESCE(assertion_id, 0), COALESCE(correction_id, 0), evidence_id) WHERE num_nonnulls(assertion_id, correction_id) = 1'
            );

            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_evidence_bindings
    ADD CONSTRAINT eg_project_model_evidence_assertion_scope_fk FOREIGN KEY (assertion_id, building_model_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_assertions (id, building_model_id, organization_id, project_id, session_id, source_version) ON DELETE CASCADE NOT VALID,
    ADD CONSTRAINT eg_project_model_evidence_correction_scope_fk FOREIGN KEY (correction_id, building_model_id, organization_id, project_id, session_id, source_version) REFERENCES estimate_generation_project_model_corrections (id, building_model_id, organization_id, project_id, session_id, source_version) ON DELETE CASCADE NOT VALID,
    ADD CONSTRAINT eg_project_model_evidence_candidate_subject_ck CHECK (num_nonnulls(assertion_id, correction_id) = 1) NOT VALID,
    ADD CONSTRAINT eg_project_model_evidence_candidate_source_ck CHECK (candidate_source IN ('manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry')) NOT VALID,
    ADD CONSTRAINT eg_project_model_evidence_candidate_fingerprint_ck CHECK (candidate_value_fingerprint ~ '^[a-f0-9]{64}$') NOT VALID;

CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
    assertion_entity_id bigint;
    assertion_source text;
    correction_assertion_id bigint;
    correction_source text;
BEGIN
    SELECT source_version, invalidation_version, invalidated_at
    INTO actual_source_version, actual_invalidation_version, actual_invalidated_at
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR UPDATE;
    IF NOT FOUND OR actual_invalidated_at IS NOT NULL OR actual_source_version <> NEW.evidence_source_version OR actual_invalidation_version <> NEW.evidence_invalidation_version THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    PERFORM 1 FROM estimate_generation_building_model_evidence
    WHERE building_model_id = NEW.building_model_id AND evidence_id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    IF num_nonnulls(NEW.assertion_id, NEW.correction_id) <> 1
        OR NEW.candidate_source IS NULL OR NEW.candidate_value_fingerprint IS NULL THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
    END IF;
    IF NEW.assertion_id IS NOT NULL THEN
        SELECT entity_id, payload->>'source' INTO assertion_entity_id, assertion_source
        FROM estimate_generation_project_model_assertions WHERE id = NEW.assertion_id;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR assertion_source <> NEW.candidate_source THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
    ELSE
        SELECT assertion_id, CASE correction_type WHEN 'manual' THEN 'manual_correction' WHEN 'source_reconciliation' THEN 'reconciled_geometry' END
        INTO correction_assertion_id, correction_source
        FROM estimate_generation_project_model_corrections WHERE id = NEW.correction_id;
        SELECT entity_id INTO assertion_entity_id FROM estimate_generation_project_model_assertions WHERE id = correction_assertion_id;
        IF NOT FOUND OR assertion_entity_id <> NEW.entity_id OR correction_source <> NEW.candidate_source THEN
            RAISE EXCEPTION 'estimate_generation.project_model_evidence_candidate_invalid';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
SQL);
            DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings DROP CONSTRAINT IF EXISTS eg_project_model_evidence_binding_uq');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_project_model_evidence_bindings', function (Blueprint $table): void {
                $table->dropForeign('eg_project_model_evidence_correction_scope_fk');
                $table->dropForeign('eg_project_model_evidence_assertion_scope_fk');
                $table->dropUnique('eg_project_model_evidence_candidate_binding_uq');
                $table->dropIndex('eg_project_model_evidence_correction_idx');
                $table->dropIndex('eg_project_model_evidence_assertion_idx');
                $table->dropColumn(['candidate_value_fingerprint', 'candidate_source', 'correction_id', 'assertion_id']);
            });

            return;
        }

        $duplicate = DB::table('estimate_generation_project_model_evidence_bindings')
            ->select(['entity_id', 'evidence_id'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy(['entity_id', 'evidence_id'])
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_bindings');
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $runtime->ensureConcurrentIndex(
                'eg_project_model_evidence_binding_uq',
                'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_evidence_binding_uq ON estimate_generation_project_model_evidence_bindings (entity_id, evidence_id)'
            );
            DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings ADD CONSTRAINT eg_project_model_evidence_binding_uq UNIQUE USING INDEX eg_project_model_evidence_binding_uq');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_evidence_candidate_binding_uq');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_evidence_bindings
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_fingerprint_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_source_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_candidate_subject_ck,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_correction_scope_fk,
    DROP CONSTRAINT IF EXISTS eg_project_model_evidence_assertion_scope_fk;
SQL);

        Schema::table('estimate_generation_project_model_evidence_bindings', function (Blueprint $table): void {
            $table->dropIndex('eg_project_model_evidence_correction_idx');
            $table->dropIndex('eg_project_model_evidence_assertion_idx');
            $table->dropColumn(['candidate_value_fingerprint', 'candidate_source', 'correction_id', 'assertion_id']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
BEGIN
    SELECT source_version, invalidation_version, invalidated_at
    INTO actual_source_version, actual_invalidation_version, actual_invalidated_at
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR UPDATE;
    IF NOT FOUND OR actual_invalidated_at IS NOT NULL OR actual_source_version <> NEW.evidence_source_version OR actual_invalidation_version <> NEW.evidence_invalidation_version THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    PERFORM 1 FROM estimate_generation_building_model_evidence
    WHERE building_model_id = NEW.building_model_id AND evidence_id = NEW.evidence_id
      AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    RETURN NEW;
END; $$;
SQL);
    }
};
