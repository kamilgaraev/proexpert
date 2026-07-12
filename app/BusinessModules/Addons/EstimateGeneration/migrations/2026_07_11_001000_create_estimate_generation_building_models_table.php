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
        Schema::create('estimate_generation_building_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('input_version', 71);
            $table->string('model_version', 64);
            $table->string('content_version', 71);
            $table->string('scale_status', 16);
            $table->decimal('scale_meters_per_unit', 18, 12)->nullable();
            $table->jsonb('model');
            $table->jsonb('assumptions');
            $table->jsonb('metrics');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'project_id', 'session_id', 'input_version'], 'eg_building_models_semantic_uq');
            $table->unique(['id', 'organization_id', 'project_id', 'session_id'], 'eg_building_models_scope_uq');
            $table->index(['session_id', 'input_version'], 'eg_building_models_session_input_idx');
            $table->index(['organization_id', 'project_id', 'content_version'], 'eg_building_models_content_idx');
            $table->index(['organization_id', 'project_id', 'scale_status', 'created_at'], 'eg_building_models_review_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_building_models_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });

        Schema::create('estimate_generation_building_model_evidence', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_model_id');
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['building_model_id', 'evidence_id'], 'eg_building_model_evidence_pk');
            $table->index(['organization_id', 'project_id', 'session_id', 'evidence_id'], 'eg_building_model_evidence_scope_idx');
            $table->foreign(['building_model_id', 'organization_id', 'project_id', 'session_id'], 'eg_building_model_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_building_models')->cascadeOnDelete();
            $table->foreign(['evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_building_model_evidence_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_evidence')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_building_model_json_object_length(value jsonb) RETURNS integer LANGUAGE sql IMMUTABLE STRICT AS $$
    SELECT count(*)::integer FROM jsonb_object_keys(value)
$$;

ALTER TABLE estimate_generation_building_models
    ADD CONSTRAINT eg_building_models_model_version_ck CHECK (model_version = 'building-model:v1'),
    ADD CONSTRAINT eg_building_models_input_version_ck CHECK (input_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_building_models_content_version_ck CHECK (content_version ~ '^sha256:[a-f0-9]{64}$'),
    ADD CONSTRAINT eg_building_models_scale_status_ck CHECK (scale_status IN ('confirmed','estimated','unknown')),
    ADD CONSTRAINT eg_building_models_scale_value_ck CHECK ((scale_status = 'unknown' AND scale_meters_per_unit IS NULL) OR (scale_status IN ('confirmed','estimated') AND scale_meters_per_unit > 0)),
    ADD CONSTRAINT eg_building_models_json_shape_ck CHECK (
        jsonb_typeof(model) = 'object'
        AND eg_building_model_json_object_length(model) = 9
        AND model ?& ARRAY['model_version','coordinate_system','unit','scale_status','scale_meters_per_unit','floors','assumptions','evidence_ids','metrics']
        AND jsonb_typeof(model->'model_version') = 'string'
        AND jsonb_typeof(model->'coordinate_system') = 'string'
        AND jsonb_typeof(model->'unit') = 'string'
        AND jsonb_typeof(model->'scale_status') = 'string'
        AND jsonb_typeof(model->'floors') = 'array'
        AND jsonb_typeof(model->'assumptions') = 'array'
        AND jsonb_typeof(model->'evidence_ids') = 'array'
        AND jsonb_typeof(model->'metrics') = 'object'
        AND jsonb_typeof(assumptions) = 'array'
        AND jsonb_typeof(metrics) = 'object'
    ),
    ADD CONSTRAINT eg_building_models_json_size_ck CHECK (octet_length(model::text) <= 4194304 AND octet_length(assumptions::text) <= 524288 AND octet_length(metrics::text) <= 65536),
    ADD CONSTRAINT eg_building_models_closed_model_ck CHECK (model - ARRAY['model_version','coordinate_system','unit','scale_status','scale_meters_per_unit','floors','assumptions','evidence_ids','metrics'] = '{}'::jsonb),
    ADD CONSTRAINT eg_building_models_model_semantics_ck CHECK (
        model->>'model_version' = model_version
        AND model->>'coordinate_system' = 'metric-right-handed-2d:v1'
        AND model->>'unit' = 'm'
        AND model->>'scale_status' = scale_status
        AND model->'assumptions' = assumptions
        AND model->'metrics' = metrics
        AND ((scale_status = 'unknown' AND jsonb_typeof(model->'scale_meters_per_unit') = 'null')
            OR (scale_status IN ('confirmed','estimated') AND jsonb_typeof(model->'scale_meters_per_unit') = 'number'
                AND (model->>'scale_meters_per_unit')::numeric = scale_meters_per_unit))
    );

CREATE FUNCTION eg_building_model_semantic_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF eg_building_model_json_object_length(NEW.metrics) <> 8
        OR NOT NEW.metrics ?& ARRAY['floor_count','room_count','wall_count','opening_count','engineering_element_count','evidence_count','minimum_confidence','complete']
        OR jsonb_typeof(NEW.metrics->'complete') <> 'boolean'
        OR EXISTS (SELECT 1 FROM jsonb_each(NEW.metrics) entry WHERE entry.key <> 'complete' AND jsonb_typeof(entry.value) <> 'number')
        OR EXISTS (SELECT 1 FROM jsonb_each(NEW.metrics) entry WHERE entry.key NOT IN ('minimum_confidence','complete') AND entry.value::text !~ '^[0-9]+$')
        OR EXISTS (SELECT 1 FROM jsonb_each(NEW.metrics) entry WHERE entry.key NOT IN ('minimum_confidence','complete') AND (entry.value::text)::numeric > 10000)
        OR (NEW.metrics->>'floor_count')::numeric > 100
        OR (NEW.metrics->>'minimum_confidence')::numeric NOT BETWEEN 0 AND 1
        OR (NEW.metrics->>'evidence_count')::numeric < 1
    THEN RAISE EXCEPTION 'estimate_generation.building_model_metrics_invalid'; END IF;
    IF EXISTS (
        SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption
        WHERE jsonb_typeof(assumption) <> 'object'
            OR eg_building_model_json_object_length(assumption) <> 5
            OR NOT assumption ?& ARRAY['code','severity','affected_keys','evidence_ids','requires_confirmation']
            OR jsonb_typeof(assumption->'code') <> 'string'
            OR assumption->>'code' NOT IN ('geometry_conflict','scale_conflict','scale_estimated','scale_missing','element_conflict')
            OR jsonb_typeof(assumption->'severity') <> 'string'
            OR assumption->>'severity' NOT IN ('warning','blocking')
            OR jsonb_typeof(assumption->'affected_keys') <> 'array'
            OR jsonb_array_length(assumption->'affected_keys') < 1
            OR jsonb_typeof(assumption->'evidence_ids') <> 'array'
            OR jsonb_array_length(assumption->'evidence_ids') < 1
            OR jsonb_typeof(assumption->'requires_confirmation') <> 'boolean'
    ) THEN RAISE EXCEPTION 'estimate_generation.building_model_assumptions_invalid'; END IF;
    IF (NEW.scale_status = 'estimated' AND NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption
            WHERE assumption->>'code' = 'scale_estimated' AND assumption->>'severity' = 'blocking' AND assumption->>'requires_confirmation' = 'true'
        )) OR (NEW.scale_status = 'unknown' AND NOT EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption
            WHERE assumption->>'code' = 'scale_missing' AND assumption->>'severity' = 'blocking' AND assumption->>'requires_confirmation' = 'true'
        )) OR (NEW.scale_status = 'confirmed' AND EXISTS (
            SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption
            WHERE assumption->>'code' IN ('scale_estimated','scale_missing','scale_conflict')
        ))
    THEN RAISE EXCEPTION 'estimate_generation.building_model_scale_blocker_invalid'; END IF;
    IF EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption, jsonb_array_elements(assumption->'affected_keys') item_value WHERE jsonb_typeof(item_value) <> 'string' OR item_value #>> '{}' !~ '^[a-z][a-z0-9_-]{0,127}$')
        OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.assumptions) assumption, jsonb_array_elements(assumption->'evidence_ids') item_value WHERE jsonb_typeof(item_value) <> 'number' OR item_value #>> '{}' !~ '^[1-9][0-9]*$')
        OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.model->'evidence_ids') item_value WHERE jsonb_typeof(item_value) <> 'number' OR item_value #>> '{}' !~ '^[1-9][0-9]*$')
    THEN RAISE EXCEPTION 'estimate_generation.building_model_reference_invalid'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_building_model_semantic_trg BEFORE INSERT ON estimate_generation_building_models FOR EACH ROW EXECUTE FUNCTION eg_building_model_semantic_guard();

CREATE FUNCTION eg_building_model_evidence_active_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE evidence_invalidated_at timestamptz;
BEGIN
    SELECT invalidated_at INTO evidence_invalidated_at
    FROM estimate_generation_evidence
    WHERE id = NEW.evidence_id AND organization_id = NEW.organization_id AND project_id = NEW.project_id AND session_id = NEW.session_id
    FOR UPDATE;
    IF NOT FOUND OR evidence_invalidated_at IS NOT NULL THEN
        RAISE EXCEPTION 'estimate_generation.building_model_evidence_inactive';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_building_model_evidence_active_trg BEFORE INSERT ON estimate_generation_building_model_evidence FOR EACH ROW EXECUTE FUNCTION eg_building_model_evidence_active_guard();

CREATE FUNCTION eg_building_model_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'estimate_generation.building_model_update_forbidden';
    END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (
        SELECT 1 FROM estimate_generation_sessions
        WHERE id = OLD.session_id AND organization_id = OLD.organization_id AND project_id = OLD.project_id
    ) THEN
        RAISE EXCEPTION 'estimate_generation.building_model_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_building_model_immutable_trg BEFORE UPDATE OR DELETE ON estimate_generation_building_models FOR EACH ROW EXECUTE FUNCTION eg_building_model_immutable_guard();

CREATE FUNCTION eg_building_model_evidence_append_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.building_model_evidence_update_forbidden'; END IF;
    IF pg_trigger_depth() = 1 AND EXISTS (SELECT 1 FROM estimate_generation_building_models WHERE id = OLD.building_model_id) THEN
        RAISE EXCEPTION 'estimate_generation.building_model_evidence_delete_forbidden';
    END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER eg_building_model_evidence_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_building_model_evidence FOR EACH ROW EXECUTE FUNCTION eg_building_model_evidence_append_guard();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS eg_building_model_evidence_append_trg ON estimate_generation_building_model_evidence');
            DB::statement('DROP TRIGGER IF EXISTS eg_building_model_evidence_active_trg ON estimate_generation_building_model_evidence');
            DB::statement('DROP TRIGGER IF EXISTS eg_building_model_immutable_trg ON estimate_generation_building_models');
            DB::statement('DROP TRIGGER IF EXISTS eg_building_model_semantic_trg ON estimate_generation_building_models');
            DB::statement('DROP FUNCTION IF EXISTS eg_building_model_evidence_append_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_building_model_evidence_active_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_building_model_immutable_guard()');
            DB::statement('DROP FUNCTION IF EXISTS eg_building_model_semantic_guard()');
        }
        Schema::dropIfExists('estimate_generation_building_model_evidence');
        Schema::dropIfExists('estimate_generation_building_models');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS eg_building_model_json_object_length(jsonb)');
        }
    }
};
