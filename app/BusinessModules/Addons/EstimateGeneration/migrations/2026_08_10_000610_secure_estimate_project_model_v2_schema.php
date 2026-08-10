<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("SET lock_timeout TO '5s'");
        DB::statement("SET statement_timeout TO '15min'");
        $this->concurrentIndex('eg_pm_facts_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_facts_scope_uq ON estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version)');
        $this->concurrentIndex('eg_pm_facts_scope_subject_idx', 'CREATE INDEX CONCURRENTLY eg_pm_facts_scope_subject_idx ON estimate_generation_project_model_assertions (organization_id, project_id, session_id, source_version, entity_id, assertion_type, fact_version DESC, id DESC)');
        $this->concurrentIndex('eg_pm_fact_evidence_scope_idx', 'CREATE INDEX CONCURRENTLY eg_pm_fact_evidence_scope_idx ON estimate_generation_project_model_fact_evidence (organization_id, project_id, session_id, fact_id)');
        $this->concurrentIndex('eg_pm_fact_projection_current_idx', 'CREATE INDEX CONCURRENTLY eg_pm_fact_projection_current_idx ON estimate_generation_project_model_fact_projections (organization_id, project_id, session_id, is_current, entity_stable_key, fact_type)');
        $this->concurrentIndex('eg_pm_fact_projection_one_current_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_pm_fact_projection_one_current_uq ON estimate_generation_project_model_fact_projections (organization_id, project_id, session_id, entity_stable_key, fact_type) WHERE is_current');
        $this->concurrentIndex('eg_pm_cross_link_current_idx', 'CREATE INDEX CONCURRENTLY eg_pm_cross_link_current_idx ON estimate_generation_project_model_cross_document_links (organization_id, project_id, session_id, is_current, strategy)');
        $this->concurrentIndex('eg_pm_understanding_current_idx', 'CREATE INDEX CONCURRENTLY eg_pm_understanding_current_idx ON estimate_generation_project_understanding_runs (organization_id, project_id, session_id, is_current, id DESC)');

        DB::statement('ALTER TABLE estimate_generation_project_model_entities DROP CONSTRAINT IF EXISTS eg_project_model_entities_kind_ck');
        $this->constraint('estimate_generation_project_model_entities', 'eg_pm_entities_kind_v2_ck', "CHECK (entity_kind IN ('room','wall','opening','dimension','material','equipment','quantity','table','structural_element')) NOT VALID");
        $this->constraint('estimate_generation_project_model_assertions', 'eg_pm_fact_origin_ck', "CHECK (fact_origin IN ('document','ai_inference','user_assumption','ai_technology_recommendation','unresolved')) NOT VALID");
        $this->constraint('estimate_generation_project_model_assertions', 'eg_pm_fact_status_ck', "CHECK (fact_status IN ('candidate','confirmed','conflicted','unresolved','invalidated') AND NOT (fact_origin = 'unresolved' AND fact_status <> 'unresolved') AND NOT (fact_origin = 'ai_technology_recommendation' AND fact_status = 'confirmed')) NOT VALID");
        $this->constraint('estimate_generation_project_model_assertions', 'eg_pm_fact_version_ck', 'CHECK (fact_version > 0) NOT VALID');
        $this->constraint('estimate_generation_project_model_assertions', 'eg_pm_fact_value_size_ck', 'CHECK (fact_value IS NULL OR octet_length(fact_value::text) <= 1048576) NOT VALID');
        $this->constraint('estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_source_ck', "CHECK (source_version ~ '^sha256:[a-f0-9]{64}$') NOT VALID");
        $this->constraint('estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_version_ck', 'CHECK (projection_version > 0) NOT VALID');
        $this->constraint('estimate_generation_project_model_fact_projections', 'eg_pm_fact_projection_current_ck', 'CHECK ((is_current AND invalidated_at IS NULL AND replacement_source_version IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL AND replacement_source_version IS NOT NULL)) NOT VALID');
        $this->constraint('estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_status_ck', "CHECK (status IN ('linked','conflicted','unresolved','suggested')) NOT VALID");
        $this->constraint('estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_distinct_ck', 'CHECK (left_fact_id <> right_fact_id) NOT VALID');
        $this->constraint('estimate_generation_project_model_cross_document_links', 'eg_pm_cross_link_strategy_ck', "CHECK (strategy IN ('stable_key','room_number','axes','native_id','equipment_position','facade_material','ai_arbitration')) NOT VALID");
        $this->constraint('estimate_generation_project_model_cross_link_evidence', 'eg_pm_cross_link_evidence_side_ck', "CHECK (side IN ('left','right')) NOT VALID");
        $this->constraint('estimate_generation_project_model_conflicts', 'eg_pm_conflict_status_ck', "CHECK (status IN ('unresolved','resolved')) NOT VALID");
        $this->constraint('estimate_generation_project_model_conflicts', 'eg_pm_conflict_reason_ck', 'CHECK (length(btrim(reason)) > 0) NOT VALID');
        $this->constraint('estimate_generation_project_model_conflicts', 'eg_pm_conflict_version_ck', 'CHECK (conflict_version > 0) NOT VALID');
        $this->constraint('estimate_generation_project_model_derived_quantities', 'eg_pm_derived_status_ck', "CHECK (status IN ('candidate','confirmed','unresolved','invalidated') AND ((status = 'unresolved' AND value IS NULL) OR (status <> 'unresolved' AND value IS NOT NULL))) NOT VALID");
        $this->constraint('estimate_generation_project_model_derived_quantities', 'eg_pm_derived_rounding_ck', "CHECK (rounding_mode IN ('half_up','half_even','floor','ceil') AND rounding_scale <= 8) NOT VALID");
        $this->constraint('estimate_generation_project_model_derived_quantities', 'eg_pm_derived_evidence_ck', "CHECK (jsonb_typeof(evidence_lineage) = 'array' AND jsonb_typeof(unresolved_inputs) = 'array' AND octet_length(evidence_lineage::text) <= 1048576) NOT VALID");

        $this->installEntityGuard();
    }

    private function concurrentIndex(string $name, string $sql): void
    {
        $state = DB::selectOne('SELECT indexrelid::regclass::text AS name, indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
        if ($state !== null && ! (bool) $state->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
            $state = null;
        }
        if ($state === null) {
            DB::statement($sql);
        }
    }

    private function constraint(string $table, string $name, string $definition): void
    {
        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$name, $table]);
        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
    }

    private function installEntityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_project_model_entity_payload_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT COALESCE(
        jsonb_typeof(NEW.payload) = 'object'
        AND NEW.payload->>'kind' = NEW.entity_kind
        AND NEW.payload->>'key' = NEW.stable_key
        AND octet_length(NEW.payload::text) <= 1048576
        AND CASE NEW.entity_kind
            WHEN 'room' THEN (NEW.payload - ARRAY['kind','key','polygon','area_m2','name','identity','document_role'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'polygon') = 'array' AND jsonb_array_length(NEW.payload->'polygon') >= 3 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'polygon') p WHERE jsonb_typeof(p) <> 'array' OR jsonb_array_length(p) <> 2 OR EXISTS (SELECT 1 FROM jsonb_array_elements(p) c WHERE jsonb_typeof(c) <> 'number'))) OR (jsonb_typeof(NEW.payload->'area_m2') = 'number' AND (NEW.payload->>'area_m2')::numeric > 0)))
            WHEN 'wall' THEN (NEW.payload - ARRAY['kind','key','start','end','thickness_m','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'start') = 'array' AND jsonb_typeof(NEW.payload->'end') = 'array' AND jsonb_array_length(NEW.payload->'start') = 2 AND jsonb_array_length(NEW.payload->'end') = 2 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'start') c WHERE jsonb_typeof(c) <> 'number') AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'end') c WHERE jsonb_typeof(c) <> 'number'))
            WHEN 'opening' THEN (NEW.payload - ARRAY['kind','key','wall_key','type','width_m','height_m','identity','document_role'] = '{}'::jsonb AND NEW.payload->>'wall_key' ~ '^[a-z][a-z0-9:_-]{0,191}$' AND NEW.payload->>'type' IN ('door','window','gate') AND jsonb_typeof(NEW.payload->'width_m') = 'number' AND (NEW.payload->>'width_m')::numeric > 0 AND jsonb_typeof(NEW.payload->'height_m') = 'number' AND (NEW.payload->>'height_m')::numeric > 0)
            WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'value') = 'number' AND (NEW.payload->>'value')::numeric > 0 AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))
            WHEN 'quantity' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'value') = 'number' AND (NEW.payload->>'value')::numeric > 0 AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))
            WHEN 'material' THEN (NEW.payload - ARRAY['kind','key','material_code','name','properties','identity','document_role'] = '{}'::jsonb AND btrim(COALESCE(NEW.payload->>'material_code','')) <> '' AND btrim(COALESCE(NEW.payload->>'name','')) <> '' AND jsonb_typeof(NEW.payload->'properties') = 'object')
            WHEN 'equipment' THEN (NEW.payload - ARRAY['kind','key','equipment_code','name','properties','identity','document_role'] = '{}'::jsonb AND btrim(COALESCE(NEW.payload->>'equipment_code','')) <> '' AND btrim(COALESCE(NEW.payload->>'name','')) <> '' AND jsonb_typeof(NEW.payload->'properties') = 'object')
            WHEN 'table' THEN (NEW.payload - ARRAY['kind','key','columns','rows','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'columns') = 'array' AND jsonb_array_length(NEW.payload->'columns') > 0 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'columns') column_value WHERE jsonb_typeof(column_value) <> 'string' OR btrim(column_value #>> '{}') = '') AND jsonb_typeof(NEW.payload->'rows') = 'array' AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'rows') row_value WHERE jsonb_typeof(row_value) <> 'object'))
            WHEN 'structural_element' THEN (NEW.payload - ARRAY['kind','key','type','location','length_m','identity','document_role'] = '{}'::jsonb AND btrim(COALESCE(NEW.payload->>'type','')) <> '' AND ((jsonb_typeof(NEW.payload->'location') = 'array' AND jsonb_array_length(NEW.payload->'location') = 2 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'location') coordinate WHERE jsonb_typeof(coordinate) <> 'number')) OR (jsonb_typeof(NEW.payload->'length_m') = 'number' AND (NEW.payload->>'length_m')::numeric > 0)))
            ELSE false
        END,
        false
    ) THEN
        RAISE EXCEPTION 'estimate_generation.project_model_entity_payload_invalid';
    END IF;
    RETURN NEW;
END; $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project model v2 constraints are forward-only.');
    }
};
