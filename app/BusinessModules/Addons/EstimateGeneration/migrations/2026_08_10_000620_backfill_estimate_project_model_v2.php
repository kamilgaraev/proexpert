<?php

declare(strict_types=1);

use App\Contracts\Database\ForwardOnlyMigration;
use App\Support\Database\PostgresSchemaIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration implements ForwardOnlyMigration
{
    private const BATCH_SIZE = 500;

    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        try {
            $schema = (string) DB::selectOne('SELECT current_schema() AS schema_name')->schema_name;
            DB::statement('SET search_path TO '.PostgresSchemaIdentifier::quote($schema).', pg_catalog');
            DB::statement("SET lock_timeout TO '5s'");
            DB::statement("SET statement_timeout TO '5min'");
            $this->installBackfillGuard();
            try {
                $this->backfillFacts();
            } finally {
                $this->restoreAppendGuard();
            }
            $this->backfillEvidence();
            $this->backfillConflicts();
            $this->installCorrectionBackfillGuard();
            try {
                $this->backfillDecisions();
            } finally {
                $this->restoreCorrectionAppendGuard();
            }
            $this->backfillProjections();
        } finally {
            try {
                DB::statement('RESET lock_timeout');
            } finally {
                try {
                    DB::statement('RESET statement_timeout');
                } finally {
                    DB::statement('RESET search_path');
                }
            }
        }
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
    fact_value = CASE WHEN fact.payload->'value' IS NOT NULL THEN jsonb_build_object('value', fact.payload->'value') ELSE fact.payload - 'source' - 'unit' END,
    fact_unit = NULLIF(fact.payload->>'unit', '')
FROM batch WHERE fact.id = batch.id
SQL);
        } while ($affected > 0);
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
        } while ($affected > 0);
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
       CASE WHEN jsonb_typeof(payload->'canonical_value') = 'object' AND payload->'canonical_value'->'value' IS NOT NULL
            THEN jsonb_build_object('value', payload->'canonical_value'->'value')
            ELSE jsonb_build_object('value', payload->'canonical_value') END,
       NULLIF(payload->'canonical_value'->>'unit',''), created_at
FROM batch
ON CONFLICT (building_model_id, stable_key) DO NOTHING
SQL);
        } while ($affected > 0);

        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH workset AS (
    SELECT selected.id AS selected_fact_id, original.id AS original_fact_id,
           correction.organization_id, correction.project_id, correction.session_id,
           correction.source_version
    FROM estimate_generation_project_model_corrections correction
    JOIN estimate_generation_project_model_assertions original ON original.id = correction.assertion_id
    JOIN estimate_generation_project_model_assertions selected
      ON selected.organization_id = correction.organization_id
     AND selected.project_id = correction.project_id
     AND selected.session_id = correction.session_id
     AND selected.source_version = correction.source_version
     AND selected.stable_key = 'fact:decision:' || substring(correction.stable_key from 12 for 48)
    WHERE EXISTS (
        SELECT 1
        FROM estimate_generation_project_model_fact_evidence original_binding
        WHERE original_binding.fact_id = original.id
          AND NOT EXISTS (
              SELECT 1
              FROM estimate_generation_project_model_fact_evidence selected_binding
              WHERE selected_binding.fact_id = selected.id
                AND selected_binding.evidence_id = original_binding.evidence_id
          )
    )
    ORDER BY correction.id
    LIMIT 500
), batch AS (
    SELECT workset.selected_fact_id AS fact_id, binding.evidence_id,
           workset.organization_id, workset.project_id, workset.session_id,
           workset.source_version, binding.evidence_source_version,
           binding.evidence_invalidation_version, binding.created_at
    FROM workset
    JOIN estimate_generation_project_model_fact_evidence binding
      ON binding.fact_id = workset.original_fact_id
)
INSERT INTO estimate_generation_project_model_fact_evidence (
    fact_id, evidence_id, organization_id, project_id, session_id, source_version,
    evidence_source_version, evidence_invalidation_version, created_at
)
SELECT fact_id, evidence_id, organization_id, project_id, session_id, source_version,
       evidence_source_version, evidence_invalidation_version, created_at
FROM batch
ON CONFLICT (fact_id, evidence_id) DO NOTHING
SQL);
        } while ($affected > 0);

        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH workset AS (
    SELECT correction.id
    FROM estimate_generation_project_model_corrections correction
    WHERE correction.selected_fact_stable_key IS NULL
    ORDER BY correction.id
    LIMIT 500
    FOR UPDATE SKIP LOCKED
), lineage AS (
    SELECT correction.id,
           'fact:decision:' || substring(correction.stable_key from 12 for 48) AS selected_fact_stable_key,
           CASE WHEN conflict.match_count = 1 THEN conflict.stable_key END AS target_conflict_key,
           COALESCE(jsonb_agg(DISTINCT jsonb_build_object(
               'evidence_id', 'evidence:' || binding.evidence_id,
               'source_version', binding.evidence_source_version,
               'invalidation_version', binding.evidence_invalidation_version
           )) FILTER (WHERE binding.evidence_id IS NOT NULL), '[]'::jsonb)
           || CASE WHEN count(binding.evidence_id) = 0 THEN jsonb_build_array(jsonb_build_object(
               'limitation_code', 'historical_evidence_unproven'
           )) ELSE '[]'::jsonb END
           || CASE
               WHEN conflict.match_count = 0 THEN jsonb_build_array(jsonb_build_object(
                   'limitation_code', 'historical_conflict_unproven'
               ))
               WHEN conflict.match_count > 1 THEN jsonb_build_array(jsonb_build_object(
                   'limitation_code', 'historical_conflict_ambiguous'
               ))
               ELSE '[]'::jsonb
           END AS evidence_lineage
    FROM workset
    JOIN estimate_generation_project_model_corrections correction ON correction.id = workset.id
    JOIN estimate_generation_project_model_assertions original ON original.id = correction.assertion_id
    LEFT JOIN estimate_generation_project_model_fact_evidence binding ON binding.fact_id = original.id
      AND binding.organization_id = correction.organization_id
      AND binding.project_id = correction.project_id AND binding.session_id = correction.session_id
    LEFT JOIN LATERAL (
        SELECT min(candidate.stable_key) AS stable_key, count(*) AS match_count
        FROM estimate_generation_project_model_conflicts candidate
        WHERE EXISTS (
              SELECT 1
              FROM estimate_generation_project_model_conflict_facts conflict_fact
              WHERE conflict_fact.conflict_id = candidate.id
                AND conflict_fact.fact_id = original.id
          )
           AND candidate.organization_id = correction.organization_id
           AND candidate.project_id = correction.project_id
           AND candidate.session_id = correction.session_id
           AND candidate.source_version = correction.source_version
          AND NOT EXISTS (
              SELECT 1
              FROM estimate_generation_project_model_conflict_facts participant
              JOIN estimate_generation_project_model_assertions participant_fact ON participant_fact.id = participant.fact_id
              WHERE participant.conflict_id = candidate.id
                AND (participant_fact.entity_id <> original.entity_id
                     OR participant_fact.assertion_type <> original.assertion_type
                     OR participant_fact.source_version <> original.source_version
                     OR NOT EXISTS (
                         SELECT 1
                         FROM estimate_generation_project_model_fact_evidence participant_lineage
                         JOIN estimate_generation_evidence participant_evidence ON participant_evidence.id = participant_lineage.evidence_id
                           AND participant_evidence.organization_id = participant_lineage.organization_id
                           AND participant_evidence.project_id = participant_lineage.project_id
                           AND participant_evidence.session_id = participant_lineage.session_id
                           AND participant_evidence.source_version = participant_lineage.evidence_source_version
                           AND participant_evidence.invalidation_version = participant_lineage.evidence_invalidation_version
                           AND participant_evidence.invalidated_at IS NULL
                         WHERE participant_lineage.fact_id = participant_fact.id
                     ))
          )
          AND EXISTS (
              SELECT 1
              FROM estimate_generation_project_model_fact_evidence original_lineage
              JOIN estimate_generation_evidence evidence ON evidence.id = original_lineage.evidence_id
                AND evidence.organization_id = original_lineage.organization_id
                AND evidence.project_id = original_lineage.project_id
                AND evidence.session_id = original_lineage.session_id
                AND evidence.source_version = original_lineage.evidence_source_version
                AND evidence.invalidation_version = original_lineage.evidence_invalidation_version
                AND evidence.invalidated_at IS NULL
              WHERE original_lineage.fact_id = original.id
                AND original_lineage.organization_id = correction.organization_id
                AND original_lineage.project_id = correction.project_id
                AND original_lineage.session_id = correction.session_id
          )
    ) conflict ON true
    GROUP BY correction.id, correction.stable_key, conflict.stable_key, conflict.match_count
)
UPDATE estimate_generation_project_model_corrections correction
SET selected_fact_stable_key = lineage.selected_fact_stable_key,
    target_conflict_key = lineage.target_conflict_key,
    evidence_lineage = lineage.evidence_lineage
FROM lineage
WHERE correction.id = lineage.id
SQL);
        } while ($affected > 0);
    }

    private function backfillConflicts(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH workset AS (
    SELECT fact.id, fact.organization_id, fact.project_id, fact.session_id, fact.source_version,
           fact.entity_id, fact.assertion_type
    FROM estimate_generation_project_model_assertions fact
    JOIN estimate_generation_building_models model ON model.id = fact.building_model_id
    WHERE fact.fact_status = 'conflicted'
      AND model.id = (SELECT max(latest.id) FROM estimate_generation_building_models latest WHERE latest.organization_id = fact.organization_id AND latest.project_id = fact.project_id AND latest.session_id = fact.session_id)
      AND EXISTS (
        SELECT 1 FROM estimate_generation_project_model_assertions other
        WHERE other.organization_id = fact.organization_id AND other.project_id = fact.project_id
          AND other.session_id = fact.session_id AND other.source_version = fact.source_version
          AND other.entity_id = fact.entity_id AND other.assertion_type = fact.assertion_type
          AND other.fact_value IS DISTINCT FROM fact.fact_value
      )
      AND NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_conflicts conflict
        WHERE conflict.organization_id = fact.organization_id
          AND conflict.project_id = fact.project_id
          AND conflict.session_id = fact.session_id
          AND conflict.source_version = fact.source_version
          AND conflict.stable_key = 'conflict:backfill:' || md5(fact.organization_id || ':' || fact.project_id || ':' || fact.session_id || ':' || fact.entity_id || ':' || fact.assertion_type)
          AND conflict.conflict_version = 1
    )
    ORDER BY fact.id
    LIMIT 500
), logical_keys AS (
    SELECT organization_id, project_id, session_id, source_version, entity_id, assertion_type
    FROM workset
    GROUP BY organization_id, project_id, session_id, source_version, entity_id, assertion_type
), batch AS (
    SELECT key.*, min(fact.created_at) AS created_at
    FROM logical_keys key
    JOIN estimate_generation_project_model_assertions fact
      ON fact.organization_id = key.organization_id AND fact.project_id = key.project_id
     AND fact.session_id = key.session_id AND fact.source_version = key.source_version
     AND fact.entity_id = key.entity_id AND fact.assertion_type = key.assertion_type
    GROUP BY key.organization_id, key.project_id, key.session_id, key.source_version, key.entity_id, key.assertion_type
    HAVING count(DISTINCT fact.fact_value::text) > 1
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
        } while ($affected > 0);

        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH workset AS (
    SELECT fact.id
    FROM estimate_generation_project_model_assertions fact
    WHERE fact.fact_status = 'conflicted'
      AND EXISTS (
          SELECT 1
          FROM estimate_generation_project_model_conflicts conflict
          WHERE conflict.organization_id = fact.organization_id
            AND conflict.project_id = fact.project_id
            AND conflict.session_id = fact.session_id
            AND conflict.source_version = fact.source_version
            AND conflict.stable_key = 'conflict:backfill:' || md5(fact.organization_id || ':' || fact.project_id || ':' || fact.session_id || ':' || fact.entity_id || ':' || fact.assertion_type)
            AND NOT EXISTS (
                SELECT 1 FROM estimate_generation_project_model_conflict_facts current
                WHERE current.conflict_id = conflict.id AND current.fact_id = fact.id
            )
      )
    ORDER BY fact.id
    LIMIT 500
), batch AS (
    SELECT conflict.id AS conflict_id, fact.id AS fact_id, fact.organization_id, fact.project_id,
           fact.session_id, fact.source_version
    FROM workset
    JOIN estimate_generation_project_model_assertions fact ON fact.id = workset.id
    JOIN estimate_generation_project_model_conflicts conflict
      ON conflict.organization_id = fact.organization_id AND conflict.project_id = fact.project_id
     AND conflict.session_id = fact.session_id AND conflict.source_version = fact.source_version
     AND conflict.stable_key = 'conflict:backfill:' || md5(fact.organization_id || ':' || fact.project_id || ':' || fact.session_id || ':' || fact.entity_id || ':' || fact.assertion_type)
)
INSERT INTO estimate_generation_project_model_conflict_facts (conflict_id, fact_id, organization_id, project_id, session_id, source_version)
SELECT conflict_id, fact_id, organization_id, project_id, session_id, source_version FROM batch
ON CONFLICT (conflict_id, fact_id) DO NOTHING
SQL);
        } while ($affected > 0);
    }

    private function backfillProjections(): void
    {
        do {
            $affected = DB::affectingStatement(<<<'SQL'
WITH workset AS (
    SELECT fact.id, fact.organization_id, fact.project_id, fact.session_id,
           entity.stable_key AS entity_stable_key, fact.assertion_type
    FROM estimate_generation_project_model_assertions fact
    JOIN estimate_generation_project_model_entities entity ON entity.id = fact.entity_id
    JOIN estimate_generation_building_models model ON model.id = fact.building_model_id
    WHERE fact.fact_status IN ('confirmed','conflicted','unresolved')
      AND model.id = (SELECT max(latest.id) FROM estimate_generation_building_models latest WHERE latest.organization_id = fact.organization_id AND latest.project_id = fact.project_id AND latest.session_id = fact.session_id)
      AND NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_fact_projections projection
        WHERE projection.organization_id = fact.organization_id AND projection.project_id = fact.project_id
          AND projection.session_id = fact.session_id AND projection.entity_stable_key = entity.stable_key
          AND projection.fact_type = fact.assertion_type AND projection.is_current
      )
    ORDER BY fact.id
    LIMIT 500
), logical_keys AS (
    SELECT organization_id, project_id, session_id, entity_stable_key, assertion_type
    FROM workset
    GROUP BY organization_id, project_id, session_id, entity_stable_key, assertion_type
), batch AS (
    SELECT selected.id, selected.organization_id, selected.project_id, selected.session_id,
           selected.source_version, key.entity_stable_key, selected.assertion_type, selected.fact_version
    FROM logical_keys key
    JOIN LATERAL (
        SELECT fact.*
        FROM estimate_generation_project_model_assertions fact
        JOIN estimate_generation_project_model_entities entity ON entity.id = fact.entity_id
        WHERE fact.organization_id = key.organization_id AND fact.project_id = key.project_id
          AND fact.session_id = key.session_id AND entity.stable_key = key.entity_stable_key
          AND fact.assertion_type = key.assertion_type
          AND fact.fact_status IN ('confirmed','conflicted','unresolved')
        ORDER BY fact.fact_version DESC, fact.id DESC
        LIMIT 1
    ) selected ON true
    WHERE NOT EXISTS (
        SELECT 1 FROM estimate_generation_project_model_fact_projections projection
        WHERE projection.organization_id = selected.organization_id AND projection.project_id = selected.project_id
          AND projection.session_id = selected.session_id AND projection.fact_id = selected.id
          AND projection.projection_version = selected.fact_version
    )
)
INSERT INTO estimate_generation_project_model_fact_projections (
    organization_id, project_id, session_id, source_version, fact_id, entity_stable_key,
    fact_type, projection_version, is_current, created_at
)
SELECT organization_id, project_id, session_id, source_version, id, entity_stable_key,
       assertion_type, fact_version, true, now() FROM batch
ON CONFLICT (organization_id, project_id, session_id, fact_id, projection_version) DO NOTHING
SQL);
        } while ($affected > 0);
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

    private function installCorrectionBackfillGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_pm_correction_v2_backfill_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE'
       OR OLD.selected_fact_stable_key IS NOT NULL
       OR NEW.selected_fact_stable_key IS NULL
       OR (to_jsonb(NEW) - ARRAY['selected_fact_stable_key','target_conflict_key','evidence_lineage'])
          IS DISTINCT FROM
          (to_jsonb(OLD) - ARRAY['selected_fact_stable_key','target_conflict_key','evidence_lineage']) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_append_only';
    END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_project_model_correction_append_trg ON estimate_generation_project_model_corrections;
CREATE TRIGGER eg_project_model_correction_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_corrections FOR EACH ROW EXECUTE FUNCTION eg_pm_correction_v2_backfill_guard();
SQL);
    }

    private function restoreCorrectionAppendGuard(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_project_model_correction_append_trg ON estimate_generation_project_model_corrections;
CREATE TRIGGER eg_project_model_correction_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_corrections FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP FUNCTION IF EXISTS eg_pm_correction_v2_backfill_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project model v2 backfill is forward-only.');
    }
};
