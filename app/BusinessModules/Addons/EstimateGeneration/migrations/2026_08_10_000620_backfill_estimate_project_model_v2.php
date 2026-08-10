<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BATCH_SIZE = 500;

    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("SET lock_timeout TO '5s'");
        DB::statement("SET statement_timeout TO '5min'");
        $this->installBackfillGuard();
        try {
            $this->backfillFacts();
        } finally {
            $this->restoreAppendGuard();
        }
        $this->backfillEvidence();
        $this->backfillDecisions();
        $this->backfillConflicts();
        $this->backfillProjections();
    }

    private function backfillFacts(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT fact.id
    FROM estimate_generation_project_model_assertions fact
    WHERE fact.fact_value IS NULL
    ORDER BY fact.id
    LIMIT 500
    FOR UPDATE SKIP LOCKED
)
UPDATE estimate_generation_project_model_assertions fact
SET fact_origin = CASE fact.payload->>'source' WHEN 'ai_candidate' THEN 'ai_inference' ELSE 'document' END,
    fact_status = CASE
        WHEN EXISTS (
            SELECT 1 FROM estimate_generation_project_model_assertions other
            JOIN estimate_generation_project_model_evidence_bindings binding ON binding.assertion_id = other.id
            JOIN estimate_generation_evidence evidence ON evidence.id = binding.evidence_id
                AND evidence.organization_id = binding.organization_id
                AND evidence.project_id = binding.project_id
                AND evidence.session_id = binding.session_id
                AND evidence.invalidated_at IS NULL
            WHERE other.entity_id = fact.entity_id AND other.assertion_type = fact.assertion_type
              AND other.id <> fact.id AND (other.payload - 'source') IS DISTINCT FROM (fact.payload - 'source')
        ) THEN 'conflicted'
        WHEN EXISTS (
            SELECT 1 FROM estimate_generation_project_model_evidence_bindings binding
            JOIN estimate_generation_evidence evidence ON evidence.id = binding.evidence_id
                AND evidence.organization_id = binding.organization_id
                AND evidence.project_id = binding.project_id
                AND evidence.session_id = binding.session_id
                AND evidence.source_version = binding.evidence_source_version
                AND evidence.invalidation_version = binding.evidence_invalidation_version
                AND evidence.invalidated_at IS NULL
            WHERE binding.assertion_id = fact.id
        ) THEN 'confirmed'
        ELSE 'candidate'
    END,
    fact_value = CASE WHEN fact.payload ? 'value' THEN jsonb_build_object('value', fact.payload->'value') ELSE fact.payload - 'source' - 'unit' END,
    fact_unit = NULLIF(fact.payload->>'unit', '')
FROM batch WHERE fact.id = batch.id
SQL);
        } while ($affected === self::BATCH_SIZE);
    }

    private function backfillEvidence(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT binding.assertion_id, binding.evidence_id, binding.organization_id, binding.project_id,
           binding.session_id, binding.source_version, binding.evidence_source_version,
           binding.evidence_invalidation_version, binding.created_at
    FROM estimate_generation_project_model_evidence_bindings binding
    JOIN estimate_generation_evidence evidence ON evidence.id = binding.evidence_id
        AND evidence.organization_id = binding.organization_id
        AND evidence.project_id = binding.project_id
        AND evidence.session_id = binding.session_id
        AND evidence.source_version = binding.evidence_source_version
        AND evidence.invalidation_version = binding.evidence_invalidation_version
        AND evidence.invalidated_at IS NULL
    WHERE binding.assertion_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM estimate_generation_project_model_fact_evidence current WHERE current.fact_id = binding.assertion_id AND current.evidence_id = binding.evidence_id)
    ORDER BY binding.assertion_id, binding.evidence_id
    LIMIT 500
)
INSERT INTO estimate_generation_project_model_fact_evidence (
    fact_id, evidence_id, organization_id, project_id, session_id, source_version,
    evidence_source_version, evidence_invalidation_version, created_at
)
SELECT assertion_id, evidence_id, organization_id, project_id, session_id, source_version,
       evidence_source_version, evidence_invalidation_version, created_at FROM batch
ON CONFLICT (fact_id, evidence_id) DO NOTHING
SQL);
        } while ($affected === self::BATCH_SIZE);
    }

    private function backfillDecisions(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT correction.*, original.entity_id, original.assertion_type, original.confidence,
           original.stable_key AS original_stable_key
    FROM estimate_generation_project_model_corrections correction
    JOIN estimate_generation_project_model_assertions original ON original.id = correction.assertion_id
    WHERE NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_assertions selected
        WHERE selected.organization_id = correction.organization_id
          AND selected.project_id = correction.project_id
          AND selected.session_id = correction.session_id
          AND selected.source_version = correction.source_version
          AND selected.stable_key = 'fact:decision:' || substring(correction.stable_key from 12 for 48)
    )
    ORDER BY correction.id
    LIMIT 500
)
INSERT INTO estimate_generation_project_model_assertions (
    building_model_id, organization_id, project_id, session_id, source_version, stable_key,
    entity_id, assertion_type, payload, confidence, fact_origin, fact_status, fact_version,
    supersedes_assertion_id, fact_value, fact_unit, created_at
)
SELECT building_model_id, organization_id, project_id, session_id, source_version,
       'fact:decision:' || substring(stable_key from 12 for 48), entity_id, assertion_type,
       jsonb_build_object('source','user_assumption','value',payload->'canonical_value'),
       1.0, 'user_assumption', 'confirmed', decision_version + 1, assertion_id,
       CASE WHEN jsonb_typeof(payload->'canonical_value') = 'object' AND payload->'canonical_value' ? 'value'
            THEN jsonb_build_object('value', payload->'canonical_value'->'value')
            ELSE jsonb_build_object('value', payload->'canonical_value') END,
       NULLIF(payload->'canonical_value'->>'unit',''), created_at
FROM batch
ON CONFLICT (building_model_id, stable_key) DO NOTHING
SQL);
        } while ($affected === self::BATCH_SIZE);
    }

    private function backfillConflicts(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH candidates AS (
    SELECT fact.organization_id, fact.project_id, fact.session_id, fact.source_version,
           fact.entity_id, fact.assertion_type, min(fact.created_at) AS created_at
    FROM estimate_generation_project_model_assertions fact
    JOIN estimate_generation_building_models model ON model.id = fact.building_model_id
    WHERE fact.fact_status = 'conflicted'
      AND model.id = (SELECT max(latest.id) FROM estimate_generation_building_models latest WHERE latest.organization_id = fact.organization_id AND latest.project_id = fact.project_id AND latest.session_id = fact.session_id)
    GROUP BY fact.organization_id, fact.project_id, fact.session_id, fact.source_version, fact.entity_id, fact.assertion_type
    HAVING count(DISTINCT fact.fact_value::text) > 1
), batch AS (
    SELECT candidate.* FROM candidates candidate
    WHERE NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_conflicts conflict
        WHERE conflict.organization_id = candidate.organization_id
          AND conflict.project_id = candidate.project_id
          AND conflict.session_id = candidate.session_id
          AND conflict.source_version = candidate.source_version
          AND conflict.stable_key = 'conflict:backfill:' || md5(candidate.organization_id || ':' || candidate.project_id || ':' || candidate.session_id || ':' || candidate.entity_id || ':' || candidate.assertion_type)
          AND conflict.conflict_version = 1
    )
    ORDER BY candidate.organization_id, candidate.project_id, candidate.session_id, candidate.entity_id, candidate.assertion_type
    LIMIT 500
)
INSERT INTO estimate_generation_project_model_conflicts (
    organization_id, project_id, session_id, source_version, stable_key, reason, status, conflict_version, created_at
)
SELECT organization_id, project_id, session_id, source_version,
       'conflict:backfill:' || md5(organization_id || ':' || project_id || ':' || session_id || ':' || entity_id || ':' || assertion_type),
       'historical_value_mismatch', 'unresolved', 1, created_at
FROM batch
ON CONFLICT (organization_id, project_id, session_id, source_version, stable_key, conflict_version) DO NOTHING
SQL);
        } while ($affected === self::BATCH_SIZE);

        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT conflict.id AS conflict_id, fact.id AS fact_id, fact.organization_id, fact.project_id,
           fact.session_id, fact.source_version
    FROM estimate_generation_project_model_conflicts conflict
    JOIN estimate_generation_project_model_assertions fact
      ON fact.organization_id = conflict.organization_id AND fact.project_id = conflict.project_id
     AND fact.session_id = conflict.session_id AND fact.source_version = conflict.source_version
     AND conflict.stable_key = 'conflict:backfill:' || md5(fact.organization_id || ':' || fact.project_id || ':' || fact.session_id || ':' || fact.entity_id || ':' || fact.assertion_type)
    WHERE conflict.stable_key LIKE 'conflict:backfill:%' AND fact.fact_status = 'conflicted'
      AND NOT EXISTS (
          SELECT 1 FROM estimate_generation_project_model_conflict_facts current
          WHERE current.conflict_id = conflict.id AND current.fact_id = fact.id
      )
    ORDER BY conflict.id, fact.id
    LIMIT 500
)
INSERT INTO estimate_generation_project_model_conflict_facts (conflict_id, fact_id, organization_id, project_id, session_id, source_version)
SELECT conflict_id, fact_id, organization_id, project_id, session_id, source_version FROM batch
ON CONFLICT (conflict_id, fact_id) DO NOTHING
SQL);
        } while ($affected === self::BATCH_SIZE);
    }

    private function backfillProjections(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH ranked AS (
    SELECT fact.id, fact.organization_id, fact.project_id, fact.session_id, fact.source_version,
           entity.stable_key AS entity_stable_key, fact.assertion_type, fact.fact_version,
           row_number() OVER (PARTITION BY fact.organization_id, fact.project_id, fact.session_id, entity.stable_key, fact.assertion_type ORDER BY fact.fact_version DESC, fact.id DESC) AS position
    FROM estimate_generation_project_model_assertions fact
    JOIN estimate_generation_project_model_entities entity ON entity.id = fact.entity_id
    JOIN estimate_generation_building_models model ON model.id = fact.building_model_id
    WHERE fact.fact_status IN ('confirmed','conflicted','unresolved')
      AND model.id = (SELECT max(latest.id) FROM estimate_generation_building_models latest WHERE latest.organization_id = fact.organization_id AND latest.project_id = fact.project_id AND latest.session_id = fact.session_id)
), batch AS (
    SELECT * FROM ranked candidate
    WHERE position = 1 AND NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_fact_projections projection
        WHERE projection.organization_id = candidate.organization_id AND projection.project_id = candidate.project_id
          AND projection.session_id = candidate.session_id AND projection.fact_id = candidate.id
          AND projection.projection_version = candidate.fact_version
    )
    ORDER BY id LIMIT 500
)
INSERT INTO estimate_generation_project_model_fact_projections (
    organization_id, project_id, session_id, source_version, fact_id, entity_stable_key,
    fact_type, projection_version, is_current, created_at
)
SELECT organization_id, project_id, session_id, source_version, id, entity_stable_key,
       assertion_type, fact_version, true, now() FROM batch
ON CONFLICT (organization_id, project_id, session_id, fact_id, projection_version) DO NOTHING
SQL);
        } while ($affected === self::BATCH_SIZE);
    }

    private function installBackfillGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_pm_assertion_v2_backfill_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.fact_value IS NOT NULL
       OR NEW.id <> OLD.id OR NEW.building_model_id <> OLD.building_model_id
       OR NEW.organization_id <> OLD.organization_id OR NEW.project_id <> OLD.project_id
       OR NEW.session_id <> OLD.session_id OR NEW.source_version <> OLD.source_version
       OR NEW.stable_key <> OLD.stable_key OR NEW.entity_id <> OLD.entity_id
       OR NEW.assertion_type <> OLD.assertion_type OR NEW.payload <> OLD.payload
       OR NEW.confidence <> OLD.confidence OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'estimate_generation.project_model_append_only';
    END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_project_model_assertion_append_trg ON estimate_generation_project_model_assertions;
CREATE TRIGGER eg_project_model_assertion_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_assertions FOR EACH ROW EXECUTE FUNCTION eg_pm_assertion_v2_backfill_guard();
SQL);
    }

    private function restoreAppendGuard(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_project_model_assertion_append_trg ON estimate_generation_project_model_assertions;
CREATE TRIGGER eg_project_model_assertion_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_assertions FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP FUNCTION IF EXISTS eg_pm_assertion_v2_backfill_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project model v2 backfill is forward-only.');
    }
};
