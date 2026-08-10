<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENTITIES = 'estimate_generation_project_model_entities';

    private const FACTS = 'estimate_generation_project_model_assertions';

    private const EVIDENCE_BINDINGS = 'estimate_generation_project_model_evidence_bindings';

    private const DECISIONS = 'estimate_generation_project_model_corrections';

    public $withinTransaction = true;

    public function up(): void
    {
        $this->extendEntities();
        $this->extendFacts();
        $this->extendDecisions();
        $this->createFactEvidence();
        $this->createFactProjections();
        $this->createConflicts();
        $this->createDerivedQuantities();
        $this->createCrossDocumentLinks();
        $this->installPostgresGuards();
    }

    private function extendEntities(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::ENTITIES.' DROP CONSTRAINT IF EXISTS eg_project_model_entities_kind_ck');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_entities
    ADD CONSTRAINT eg_project_model_entities_kind_ck CHECK (
        entity_kind IN ('room', 'wall', 'opening', 'dimension', 'material', 'equipment', 'quantity', 'table', 'structural_element')
    )
SQL);
    }

    private function extendFacts(): void
    {
        Schema::table(self::FACTS, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::FACTS, 'fact_origin')) {
                $table->string('fact_origin', 48)->default('document');
            }
            if (! Schema::hasColumn(self::FACTS, 'fact_status')) {
                $table->string('fact_status', 32)->default('candidate');
            }
            if (! Schema::hasColumn(self::FACTS, 'fact_version')) {
                $table->unsignedInteger('fact_version')->default(1);
            }
            if (! Schema::hasColumn(self::FACTS, 'supersedes_assertion_id')) {
                $table->unsignedBigInteger('supersedes_assertion_id')->nullable();
            }
            if (! Schema::hasColumn(self::FACTS, 'fact_value')) {
                $table->jsonb('fact_value')->nullable();
            }
            if (! Schema::hasColumn(self::FACTS, 'fact_unit')) {
                $table->string('fact_unit', 32)->nullable();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS eg_pm_facts_scope_uq
ON estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version)
SQL);
        DB::statement(<<<'SQL'
UPDATE estimate_generation_project_model_assertions
SET fact_origin = CASE payload->>'source'
        WHEN 'ai_candidate' THEN 'ai_inference'
        ELSE 'document'
    END,
    fact_value = COALESCE(fact_value, payload - 'source' - 'unit'),
    fact_unit = COALESCE(fact_unit, NULLIF(payload->>'unit', ''))
WHERE fact_value IS NULL
SQL);
        DB::statement('ALTER TABLE '.self::FACTS.' DROP CONSTRAINT IF EXISTS eg_pm_fact_origin_ck');
        DB::statement('ALTER TABLE '.self::FACTS.' DROP CONSTRAINT IF EXISTS eg_pm_fact_status_ck');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_assertions
    ADD CONSTRAINT eg_pm_fact_origin_ck CHECK (
        fact_origin IN ('document', 'ai_inference', 'user_assumption', 'ai_technology_recommendation', 'unresolved')
    ),
    ADD CONSTRAINT eg_pm_fact_status_ck CHECK (
        fact_status IN ('candidate', 'confirmed', 'conflicted', 'unresolved', 'invalidated')
        AND NOT (fact_origin = 'unresolved' AND fact_status <> 'unresolved')
        AND NOT (fact_origin = 'ai_technology_recommendation' AND fact_status = 'confirmed')
    ),
    ADD CONSTRAINT eg_pm_fact_version_ck CHECK (fact_version > 0),
    ADD CONSTRAINT eg_pm_fact_value_size_ck CHECK (fact_value IS NULL OR octet_length(fact_value::text) <= 1048576)
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS eg_pm_facts_scope_subject_idx
ON estimate_generation_project_model_assertions
    (organization_id, project_id, session_id, source_version, entity_id, assertion_type, fact_version DESC)
SQL);
    }

    private function extendDecisions(): void
    {
        Schema::table(self::DECISIONS, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::DECISIONS, 'decision_actor_type')) {
                $table->string('decision_actor_type', 16)->default('user');
            }
            if (! Schema::hasColumn(self::DECISIONS, 'decision_version')) {
                $table->unsignedInteger('decision_version')->default(1);
            }
            if (! Schema::hasColumn(self::DECISIONS, 'target_conflict_key')) {
                $table->string('target_conflict_key', 192)->nullable();
            }
            if (! Schema::hasColumn(self::DECISIONS, 'selected_fact_stable_key')) {
                $table->string('selected_fact_stable_key', 192)->nullable();
            }
            if (! Schema::hasColumn(self::DECISIONS, 'system_actor_key')) {
                $table->string('system_actor_key', 191)->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_corrections
    ALTER COLUMN actor_id DROP NOT NULL,
    ADD CONSTRAINT eg_pm_decision_actor_type_ck CHECK (decision_actor_type IN ('user', 'system')),
    ADD CONSTRAINT eg_pm_decision_actor_ck CHECK (
        (decision_actor_type = 'user' AND actor_id IS NOT NULL AND system_actor_key IS NULL)
        OR (decision_actor_type = 'system' AND actor_id IS NULL AND length(btrim(system_actor_key)) > 0)
    ),
    ADD CONSTRAINT eg_pm_decision_version_ck CHECK (decision_version > 0)
SQL);
        }
    }

    private function createFactProjections(): void
    {
        if (Schema::hasTable('estimate_generation_project_model_fact_projections')) {
            return;
        }

        Schema::create('estimate_generation_project_model_fact_projections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->unsignedBigInteger('fact_id');
            $table->string('entity_stable_key', 192);
            $table->string('fact_type', 64);
            $table->unsignedInteger('projection_version');
            $table->boolean('is_current')->default(true);
            $table->string('replacement_source_version', 71)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_fact_projection_scope_uq');
            $table->index(['organization_id', 'project_id', 'session_id', 'is_current', 'entity_stable_key', 'fact_type'], 'eg_pm_fact_projection_current_idx');
            $table->unique(['organization_id', 'project_id', 'session_id', 'fact_id', 'projection_version'], 'eg_pm_fact_projection_replay_uq');
            $table->foreign(['fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_fact_projection_fact_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on(self::FACTS)->restrictOnDelete();
        });
    }

    private function createFactEvidence(): void
    {
        if (Schema::hasTable('estimate_generation_project_model_fact_evidence')) {
            return;
        }

        Schema::create('estimate_generation_project_model_fact_evidence', function (Blueprint $table): void {
            $table->unsignedBigInteger('fact_id');
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('evidence_source_version', 255);
            $table->unsignedInteger('evidence_invalidation_version');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['fact_id', 'evidence_id'], 'eg_pm_fact_evidence_pk');
            $table->index(['organization_id', 'project_id', 'session_id', 'fact_id'], 'eg_pm_fact_evidence_scope_idx');
            $table->foreign(['fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_fact_evidence_fact_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on(self::FACTS)->restrictOnDelete();
            $table->foreign(['evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_pm_fact_evidence_evidence_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])
                ->on('estimate_generation_evidence')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
INSERT INTO estimate_generation_project_model_fact_evidence (
    fact_id, evidence_id, organization_id, project_id, session_id, source_version,
    evidence_source_version, evidence_invalidation_version, created_at
)
SELECT assertion_id, evidence_id, organization_id, project_id, session_id, source_version,
       evidence_source_version, evidence_invalidation_version, created_at
FROM estimate_generation_project_model_evidence_bindings
WHERE assertion_id IS NOT NULL
ON CONFLICT (fact_id, evidence_id) DO NOTHING
SQL);
    }

    private function createConflicts(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_conflicts')) {
            Schema::create('estimate_generation_project_model_conflicts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->string('stable_key', 192);
                $table->string('reason', 1000);
                $table->string('status', 24)->default('unresolved');
                $table->unsignedInteger('conflict_version')->default(1);
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key', 'conflict_version'], 'eg_pm_conflict_replay_uq');
                $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_conflict_scope_uq');
                $table->index(['organization_id', 'project_id', 'session_id', 'status'], 'eg_pm_conflict_status_idx');
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_conflict_facts')) {
            Schema::create('estimate_generation_project_model_conflict_facts', function (Blueprint $table): void {
                $table->unsignedBigInteger('conflict_id');
                $table->unsignedBigInteger('fact_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->primary(['conflict_id', 'fact_id'], 'eg_pm_conflict_facts_pk');
                $table->foreign(['conflict_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_conflict_facts_conflict_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_conflicts')->restrictOnDelete();
                $table->foreign(['fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_conflict_facts_fact_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on(self::FACTS)->restrictOnDelete();
            });
        }
    }

    private function createDerivedQuantities(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_derived_quantities')) {
            Schema::create('estimate_generation_project_model_derived_quantities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->string('stable_key', 192);
                $table->string('entity_stable_key', 192);
                $table->string('formula', 2000);
                $table->decimal('value', 24, 8)->nullable();
                $table->string('unit', 32);
                $table->string('rounding_mode', 16);
                $table->unsignedSmallInteger('rounding_scale');
                $table->string('status', 24);
                $table->jsonb('evidence_lineage');
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_pm_derived_replay_uq');
                $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_derived_scope_uq');
                $table->index(['organization_id', 'project_id', 'session_id', 'entity_stable_key'], 'eg_pm_derived_entity_idx');
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_derived_operands')) {
            Schema::create('estimate_generation_project_model_derived_operands', function (Blueprint $table): void {
                $table->unsignedBigInteger('derived_quantity_id');
                $table->unsignedBigInteger('fact_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->unsignedSmallInteger('operand_ordinal');
                $table->jsonb('operand_snapshot');
                $table->primary(['derived_quantity_id', 'operand_ordinal'], 'eg_pm_derived_operands_pk');
                $table->foreign(['derived_quantity_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_derived_operands_quantity_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_derived_quantities')->restrictOnDelete();
                $table->foreign(['fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_derived_operands_fact_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on(self::FACTS)->restrictOnDelete();
            });
        }
    }

    private function createCrossDocumentLinks(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_cross_document_links')) {
            Schema::create('estimate_generation_project_model_cross_document_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->string('stable_key', 192);
                $table->unsignedBigInteger('left_fact_id');
                $table->unsignedBigInteger('right_fact_id');
                $table->string('strategy', 64);
                $table->string('reason', 1000);
                $table->unsignedInteger('strategy_version');
                $table->string('operation_identity', 64);
                $table->string('status', 24);
                $table->boolean('is_current')->default(true);
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('invalidated_at')->nullable();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'operation_identity'], 'eg_pm_cross_link_replay_uq');
                $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_cross_link_scope_uq');
                $table->index(['organization_id', 'project_id', 'session_id', 'is_current', 'strategy'], 'eg_pm_cross_link_current_idx');
                $table->foreign(['left_fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_cross_link_left_fact_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on(self::FACTS)->restrictOnDelete();
                $table->foreign(['right_fact_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_cross_link_right_fact_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])->on(self::FACTS)->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_cross_link_evidence')) {
            Schema::create('estimate_generation_project_model_cross_link_evidence', function (Blueprint $table): void {
                $table->unsignedBigInteger('link_id');
                $table->unsignedBigInteger('evidence_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->string('side', 8);
                $table->primary(['link_id', 'evidence_id', 'side'], 'eg_pm_cross_link_evidence_pk');
                $table->foreign(['link_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_pm_cross_link_evidence_link_fk')
                    ->references(['id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                    ->on('estimate_generation_project_model_cross_document_links')->restrictOnDelete();
                $table->foreign('evidence_id', 'eg_pm_cross_link_evidence_id_fk')
                    ->references('id')->on('estimate_generation_evidence')->restrictOnDelete();
            });
        }
    }

    private function installPostgresGuards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_entity_payload_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT COALESCE(
        jsonb_typeof(NEW.payload) = 'object'
        AND NEW.payload->>'kind' = NEW.entity_kind
        AND NEW.payload->>'key' = NEW.stable_key
        AND octet_length(NEW.payload::text) <= 1048576,
        false
    ) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_entity_payload_invalid';
    END IF;
    RETURN NEW;
END; $$;

ALTER TABLE estimate_generation_project_model_fact_projections
    ADD CONSTRAINT eg_pm_fact_projection_source_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_pm_fact_projection_replacement_ck CHECK (replacement_source_version IS NULL OR replacement_source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_pm_fact_projection_version_ck CHECK (projection_version > 0),
    ADD CONSTRAINT eg_pm_fact_projection_current_ck CHECK ((is_current AND invalidated_at IS NULL AND replacement_source_version IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL AND replacement_source_version IS NOT NULL));

CREATE UNIQUE INDEX eg_pm_fact_projection_one_current_uq
ON estimate_generation_project_model_fact_projections (organization_id, project_id, session_id, entity_stable_key, fact_type)
WHERE is_current;

ALTER TABLE estimate_generation_project_model_conflicts
    ADD CONSTRAINT eg_pm_conflict_source_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_pm_conflict_status_ck CHECK (status IN ('unresolved', 'resolved')),
    ADD CONSTRAINT eg_pm_conflict_reason_ck CHECK (length(btrim(reason)) > 0),
    ADD CONSTRAINT eg_pm_conflict_version_ck CHECK (conflict_version > 0);

ALTER TABLE estimate_generation_project_model_derived_quantities
    ADD CONSTRAINT eg_pm_derived_source_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_pm_derived_rounding_ck CHECK (rounding_mode IN ('half_up', 'half_even', 'floor', 'ceil') AND rounding_scale <= 8),
    ADD CONSTRAINT eg_pm_derived_status_ck CHECK (status IN ('candidate', 'confirmed', 'unresolved', 'invalidated') AND ((status = 'unresolved' AND value IS NULL) OR (status <> 'unresolved' AND value IS NOT NULL))),
    ADD CONSTRAINT eg_pm_derived_evidence_ck CHECK (jsonb_typeof(evidence_lineage) = 'array' AND octet_length(evidence_lineage::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_cross_document_links
    ADD CONSTRAINT eg_pm_cross_link_source_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_pm_cross_link_distinct_ck CHECK (left_fact_id <> right_fact_id),
    ADD CONSTRAINT eg_pm_cross_link_strategy_ck CHECK (strategy IN ('stable_key', 'room_number', 'axes', 'native_id', 'equipment_position', 'facade_material', 'ai_arbitration')),
    ADD CONSTRAINT eg_pm_cross_link_status_ck CHECK (status IN ('linked', 'conflicted', 'unresolved', 'suggested'));

ALTER TABLE estimate_generation_project_model_cross_link_evidence
    ADD CONSTRAINT eg_pm_cross_link_evidence_side_ck CHECK (side IN ('left', 'right'));

CREATE OR REPLACE FUNCTION eg_pm_fact_evidence_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id
      AND organization_id = NEW.organization_id
      AND project_id = NEW.project_id
      AND session_id = NEW.session_id
      AND source_version = NEW.evidence_source_version
      AND invalidation_version = NEW.evidence_invalidation_version
      AND invalidated_at IS NULL;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_fact_evidence_scope_invalid';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_pm_fact_evidence_scope_trg
BEFORE INSERT ON estimate_generation_project_model_fact_evidence
FOR EACH ROW EXECUTE FUNCTION eg_pm_fact_evidence_scope_guard();

CREATE OR REPLACE FUNCTION eg_pm_cross_link_evidence_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id
      AND organization_id = NEW.organization_id
      AND project_id = NEW.project_id
      AND session_id = NEW.session_id
      AND invalidated_at IS NULL;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'estimate_generation.project_model_cross_link_evidence_scope_invalid';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_pm_cross_link_evidence_scope_trg
BEFORE INSERT ON estimate_generation_project_model_cross_link_evidence
FOR EACH ROW EXECUTE FUNCTION eg_pm_cross_link_evidence_scope_guard();

CREATE TRIGGER eg_pm_conflict_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_conflicts FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_pm_conflict_fact_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_conflict_facts FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_pm_fact_evidence_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_fact_evidence FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_pm_derived_operand_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_operands FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
    }

    public function down(): void
    {
        throw new \RuntimeException('Consolidated project model v2 contains immutable evidence and audit history and cannot be rolled back destructively.');
    }
};
