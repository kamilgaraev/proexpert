<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('estimate_generation_building_models', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id', 'project_id', 'session_id', 'content_version'],
                'eg_building_models_projection_scope_uq',
            );
        });
        Schema::table('estimate_generation_building_model_evidence', function (Blueprint $table): void {
            $table->unique(
                ['building_model_id', 'evidence_id', 'organization_id', 'project_id', 'session_id'],
                'eg_building_model_evidence_projection_scope_uq',
            );
        });

        Schema::create('estimate_generation_project_model_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->string('entity_kind', 32);
            $table->jsonb('payload');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_model_id', 'stable_key'], 'eg_project_model_entities_model_key_uq');
            $table->unique(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_entities_scope_uq');
            $table->index(['building_model_id', 'entity_kind'], 'eg_project_model_entities_kind_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_entities_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'content_version'])
                ->on('estimate_generation_building_models')
                ->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_assertions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('entity_id');
            $table->string('assertion_type', 64);
            $table->jsonb('payload');
            $table->decimal('confidence', 5, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_model_id', 'stable_key'], 'eg_project_model_assertions_model_key_uq');
            $table->unique(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_scope_uq');
            $table->index(['entity_id', 'building_model_id'], 'eg_project_model_assertions_entity_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'content_version'])
                ->on('estimate_generation_building_models')
                ->cascadeOnDelete();
            $table->foreign(['entity_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_assertions_entity_scope_fk')
                ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on('estimate_generation_project_model_entities')
                ->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_relations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('from_entity_id');
            $table->unsignedBigInteger('to_entity_id');
            $table->string('relation_type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_model_id', 'stable_key'], 'eg_project_model_relations_model_key_uq');
            $table->index(['from_entity_id', 'building_model_id'], 'eg_project_model_relations_from_idx');
            $table->index(['to_entity_id', 'building_model_id'], 'eg_project_model_relations_to_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'content_version'])
                ->on('estimate_generation_building_models')
                ->cascadeOnDelete();
            $table->foreign(['from_entity_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_from_scope_fk')
                ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on('estimate_generation_project_model_entities')
                ->cascadeOnDelete();
            $table->foreign(['to_entity_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_relations_to_scope_fk')
                ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on('estimate_generation_project_model_entities')
                ->cascadeOnDelete();
        });

        Schema::create('estimate_generation_project_model_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('stable_key', 192);
            $table->unsignedBigInteger('assertion_id');
            $table->string('correction_type', 64);
            $table->jsonb('payload');
            $table->string('reason', 1000);
            $table->unsignedBigInteger('actor_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['building_model_id', 'stable_key'], 'eg_project_model_corrections_model_key_uq');
            $table->index(['assertion_id', 'building_model_id'], 'eg_project_model_corrections_assertion_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_corrections_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'content_version'])
                ->on('estimate_generation_building_models')
                ->cascadeOnDelete();
            $table->foreign(['assertion_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_corrections_assertion_scope_fk')
                ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on('estimate_generation_project_model_assertions')
                ->cascadeOnDelete();
            $table->foreign('actor_id', 'eg_project_model_corrections_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('estimate_generation_project_model_evidence_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('evidence_id');
            $table->string('evidence_source_version', 80);
            $table->unsignedInteger('evidence_invalidation_version');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['entity_id', 'evidence_id'], 'eg_project_model_evidence_binding_uq');
            $table->index(['building_model_id', 'entity_id'], 'eg_project_model_evidence_entity_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'content_version'])
                ->on('estimate_generation_building_models')
                ->cascadeOnDelete();
            $table->foreign(['entity_id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'], 'eg_project_model_evidence_entity_scope_fk')
                ->references(['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'])
                ->on('estimate_generation_project_model_entities')
                ->cascadeOnDelete();
            $table->foreign(['building_model_id', 'evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_project_model_evidence_provenance_fk')
                ->references(['building_model_id', 'evidence_id', 'organization_id', 'project_id', 'session_id'])
                ->on('estimate_generation_building_model_evidence')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_entities
    ADD CONSTRAINT eg_project_model_entities_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_entities_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_entities_kind_ck CHECK (entity_kind IN ('room', 'wall', 'opening', 'dimension', 'table', 'structural_element', 'quantity')),
    ADD CONSTRAINT eg_project_model_entities_payload_ck CHECK (
        jsonb_typeof(payload) = 'object' AND payload->>'kind' = entity_kind AND payload->>'key' = stable_key
        AND CASE entity_kind
            WHEN 'room' THEN (jsonb_typeof(payload->'polygon') = 'array' AND jsonb_array_length(payload->'polygon') >= 3 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'polygon') point WHERE jsonb_typeof(point) <> 'array' OR jsonb_array_length(point) <> 2 OR EXISTS (SELECT 1 FROM jsonb_array_elements(point) coordinate WHERE jsonb_typeof(coordinate) <> 'number'))) OR (jsonb_typeof(payload->'area_m2') = 'number' AND (payload->>'area_m2')::numeric > 0)
            WHEN 'wall' THEN jsonb_typeof(payload->'start') = 'array' AND jsonb_array_length(payload->'start') = 2 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'start') coordinate WHERE jsonb_typeof(coordinate) <> 'number') AND jsonb_typeof(payload->'end') = 'array' AND jsonb_array_length(payload->'end') = 2 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'end') coordinate WHERE jsonb_typeof(coordinate) <> 'number')
            WHEN 'opening' THEN payload->>'wall_key' ~ '^[a-z][a-z0-9:_-]{0,191}$' AND payload->>'type' IN ('door','window','gate') AND jsonb_typeof(payload->'width_m') = 'number' AND (payload->>'width_m')::numeric > 0 AND jsonb_typeof(payload->'height_m') = 'number' AND (payload->>'height_m')::numeric > 0
            WHEN 'dimension' THEN jsonb_typeof(payload->'value') = 'number' AND (payload->>'value')::numeric > 0 AND payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h')
            WHEN 'table' THEN jsonb_typeof(payload->'columns') = 'array' AND jsonb_array_length(payload->'columns') > 0 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'columns') column_value WHERE jsonb_typeof(column_value) <> 'string' OR btrim(column_value #>> '{}') = '') AND jsonb_typeof(payload->'rows') = 'array' AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'rows') row_value WHERE jsonb_typeof(row_value) <> 'object')
            WHEN 'structural_element' THEN btrim(COALESCE(payload->>'type', '')) <> '' AND ((jsonb_typeof(payload->'location') = 'array' AND jsonb_array_length(payload->'location') = 2 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(payload->'location') coordinate WHERE jsonb_typeof(coordinate) <> 'number')) OR (jsonb_typeof(payload->'length_m') = 'number' AND (payload->>'length_m')::numeric > 0))
            WHEN 'quantity' THEN jsonb_typeof(payload->'value') = 'number' AND (payload->>'value')::numeric > 0 AND payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h')
        END
    ),
    ADD CONSTRAINT eg_project_model_entities_confidence_ck CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    ADD CONSTRAINT eg_project_model_entities_size_ck CHECK (octet_length(payload::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_assertions
    ADD CONSTRAINT eg_project_model_assertions_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_assertions_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_assertions_type_ck CHECK (assertion_type ~ '^[a-z][a-z0-9_]{0,63}$'),
    ADD CONSTRAINT eg_project_model_assertions_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_assertions_confidence_ck CHECK (confidence BETWEEN 0 AND 1),
    ADD CONSTRAINT eg_project_model_assertions_size_ck CHECK (octet_length(payload::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_relations
    ADD CONSTRAINT eg_project_model_relations_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_relations_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_relations_type_ck CHECK (relation_type ~ '^[a-z][a-z0-9_]{0,63}$'),
    ADD CONSTRAINT eg_project_model_relations_distinct_entities_ck CHECK (from_entity_id <> to_entity_id),
    ADD CONSTRAINT eg_project_model_relations_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_relations_size_ck CHECK (octet_length(payload::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_corrections
    ADD CONSTRAINT eg_project_model_corrections_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_corrections_stable_key_ck CHECK (stable_key ~ '^[a-z][a-z0-9:_-]{0,191}$'),
    ADD CONSTRAINT eg_project_model_corrections_type_ck CHECK (correction_type IN ('manual', 'source_reconciliation')),
    ADD CONSTRAINT eg_project_model_corrections_payload_ck CHECK (jsonb_typeof(payload) = 'object'),
    ADD CONSTRAINT eg_project_model_corrections_reason_ck CHECK (length(btrim(reason)) > 0),
    ADD CONSTRAINT eg_project_model_corrections_size_ck CHECK (octet_length(payload::text) <= 1048576);

ALTER TABLE estimate_generation_project_model_evidence_bindings
    ADD CONSTRAINT eg_project_model_evidence_source_version_ck CHECK (source_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_project_model_evidence_source_snapshot_ck CHECK (length(btrim(evidence_source_version)) > 0),
    ADD CONSTRAINT eg_project_model_evidence_invalidation_ck CHECK (evidence_invalidation_version >= 0);

CREATE FUNCTION eg_project_model_evidence_binding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actual_source_version text;
    actual_invalidation_version integer;
    actual_invalidated_at timestamptz;
BEGIN
    SELECT source_version, invalidation_version, invalidated_at
    INTO actual_source_version, actual_invalidation_version, actual_invalidated_at
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR KEY SHARE;
    IF NOT FOUND OR actual_invalidated_at IS NOT NULL OR actual_source_version <> NEW.evidence_source_version OR actual_invalidation_version <> NEW.evidence_invalidation_version THEN
        RAISE EXCEPTION 'estimate_generation.project_model_evidence_snapshot_invalid';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_project_model_evidence_binding_guard_trg BEFORE INSERT ON estimate_generation_project_model_evidence_bindings FOR EACH ROW EXECUTE FUNCTION eg_project_model_evidence_binding_guard();

CREATE FUNCTION eg_project_model_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'estimate_generation.project_model_update_forbidden';
    END IF;
    IF pg_trigger_depth() = 1 THEN
        RAISE EXCEPTION 'estimate_generation.project_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_project_model_entity_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_entities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_project_model_assertion_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_assertions FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_project_model_relation_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_relations FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_project_model_correction_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_corrections FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
CREATE TRIGGER eg_project_model_evidence_binding_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_evidence_bindings FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_evidence_binding_append_trg ON estimate_generation_project_model_evidence_bindings');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_correction_append_trg ON estimate_generation_project_model_corrections');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_relation_append_trg ON estimate_generation_project_model_relations');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_assertion_append_trg ON estimate_generation_project_model_assertions');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_entity_append_trg ON estimate_generation_project_model_entities');
            DB::statement('DROP TRIGGER IF EXISTS eg_project_model_evidence_binding_guard_trg ON estimate_generation_project_model_evidence_bindings');
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_append_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_project_model_evidence_binding_guard()');
        }

        Schema::dropIfExists('estimate_generation_project_model_evidence_bindings');
        Schema::dropIfExists('estimate_generation_project_model_corrections');
        Schema::dropIfExists('estimate_generation_project_model_relations');
        Schema::dropIfExists('estimate_generation_project_model_assertions');
        Schema::dropIfExists('estimate_generation_project_model_entities');
        Schema::table('estimate_generation_building_model_evidence', function (Blueprint $table): void {
            $table->dropUnique('eg_building_model_evidence_projection_scope_uq');
        });
        Schema::table('estimate_generation_building_models', function (Blueprint $table): void {
            $table->dropUnique('eg_building_models_projection_scope_uq');
        });
    }
};
