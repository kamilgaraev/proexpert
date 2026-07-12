<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('estimate_generation_geometry_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('previous_building_model_id');
            $table->unsignedBigInteger('confirmed_building_model_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('previous_input_version', 71);
            $table->string('previous_content_version', 71);
            $table->string('confirmed_input_version', 71);
            $table->string('confirmed_content_version', 71);
            $table->string('source_class', 64);
            $table->string('reviewer_ref', 96);
            $table->timestampTz('confirmed_at');
            $table->jsonb('semantic_payload');
            $table->timestampsTz();
            $table->foreign(['evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_geometry_confirmation_evidence_fk')->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_evidence')->restrictOnDelete();
            $table->foreign(['previous_building_model_id', 'organization_id', 'project_id', 'session_id'], 'eg_geometry_confirmation_previous_model_fk')->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_building_models')->restrictOnDelete();
            $table->foreign(['confirmed_building_model_id', 'organization_id', 'project_id', 'session_id'], 'eg_geometry_confirmation_confirmed_model_fk')->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_building_models')->restrictOnDelete();
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_geometry_confirmation_session_fk')->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->restrictOnDelete();
            $table->unique('evidence_id');
            $table->unique('confirmed_building_model_id');
            $table->index(['organization_id', 'project_id', 'session_id', 'confirmed_input_version'], 'eg_geometry_confirmation_scope_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_geometry_confirmation_semantic_valid_v1(payload jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE item jsonb; role text; kind text;
BEGIN
  IF jsonb_typeof(payload) <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(payload)) <> 5
    OR NOT payload ?& ARRAY['schema_version','source_fingerprint','geometry_payload_sha256','scale_evidence','elements']
    OR payload->'schema_version' <> '1'::jsonb
    OR payload->>'source_fingerprint' !~ '^sha256:[a-f0-9]{64}$'
    OR payload->>'geometry_payload_sha256' !~ '^[a-f0-9]{64}$'
    OR jsonb_typeof(payload->'scale_evidence') <> 'array' OR jsonb_array_length(payload->'scale_evidence') NOT BETWEEN 1 AND 1000
    OR jsonb_typeof(payload->'elements') <> 'array' OR jsonb_array_length(payload->'elements') NOT BETWEEN 1 AND 10000
    OR octet_length(payload::text) > 262144
    OR payload::text ~* '"[^"]*(privacy|approval|audit|expected|prediction|metrics)[^"]*"[[:space:]]*:' THEN RETURN false; END IF;
  FOR item IN SELECT value FROM jsonb_array_elements(payload->'scale_evidence') LOOP
    IF jsonb_typeof(item) <> 'object' OR jsonb_typeof(item->'role') <> 'string' THEN RETURN false; END IF;
    role := item->>'role';
    IF role = 'measured_segment' THEN
      IF (item - ARRAY['role','entity_handle','point_indexes','real_world_value','unit']) <> '{}'::jsonb
        OR NOT item ?& ARRAY['role','entity_handle','point_indexes','real_world_value','unit']
        OR jsonb_typeof(item->'entity_handle') <> 'string' OR length(item->>'entity_handle') NOT BETWEEN 1 AND 512
        OR jsonb_typeof(item->'point_indexes') <> 'array' OR jsonb_array_length(item->'point_indexes') <> 2
        OR jsonb_typeof(item->'point_indexes'->0) <> 'number' OR jsonb_typeof(item->'point_indexes'->1) <> 'number'
        OR item->'point_indexes'->>0 !~ '^(0|[1-9][0-9]*)$' OR item->'point_indexes'->>1 !~ '^(0|[1-9][0-9]*)$'
        OR (item->'point_indexes'->>0)::integer < 0 OR (item->'point_indexes'->>1)::integer < 0
        OR item->'point_indexes'->0 = item->'point_indexes'->1 OR jsonb_typeof(item->'real_world_value') <> 'number'
        OR (item->>'real_world_value')::numeric <= 0 OR item->>'unit' NOT IN ('mm','cm','m','in','ft') THEN RETURN false; END IF;
    ELSIF role = 'dimension' THEN
      IF (item - ARRAY['role','value_handle','entity_handle','point_indexes']) <> '{}'::jsonb OR NOT item ?& ARRAY['role','value_handle','entity_handle','point_indexes']
        OR jsonb_typeof(item->'value_handle') <> 'string' OR length(item->>'value_handle') NOT BETWEEN 1 AND 512 OR jsonb_typeof(item->'entity_handle') <> 'string' OR length(item->>'entity_handle') NOT BETWEEN 1 AND 512
        OR jsonb_typeof(item->'point_indexes') <> 'array' OR jsonb_array_length(item->'point_indexes') <> 2
        OR jsonb_typeof(item->'point_indexes'->0) <> 'number' OR jsonb_typeof(item->'point_indexes'->1) <> 'number'
        OR item->'point_indexes'->>0 !~ '^(0|[1-9][0-9]*)$' OR item->'point_indexes'->>1 !~ '^(0|[1-9][0-9]*)$'
        OR (item->'point_indexes'->>0)::integer < 0 OR (item->'point_indexes'->>1)::integer < 0 OR item->'point_indexes'->0 = item->'point_indexes'->1 THEN RETURN false; END IF;
    ELSIF role IN ('unit_declaration','cad_header') THEN
      IF (item - ARRAY['role','value_handle']) <> '{}'::jsonb OR NOT item ?& ARRAY['role','value_handle'] OR jsonb_typeof(item->'value_handle') <> 'string' OR length(item->>'value_handle') NOT BETWEEN 1 AND 512 THEN RETURN false; END IF;
    ELSE RETURN false; END IF;
  END LOOP;
  FOR item IN SELECT value FROM jsonb_array_elements(payload->'elements') LOOP
    kind := item->>'type';
    IF jsonb_typeof(item) <> 'object' OR jsonb_typeof(item->'key') <> 'string' OR length(item->>'key') NOT BETWEEN 1 AND 512 OR kind NOT IN ('room','wall','opening') THEN RETURN false; END IF;
    IF kind='room' AND ((item - ARRAY['key','type','boundary_handle']) <> '{}'::jsonb OR NOT item ?& ARRAY['key','type','boundary_handle'] OR jsonb_typeof(item->'boundary_handle') <> 'string' OR length(item->>'boundary_handle') NOT BETWEEN 1 AND 512) THEN RETURN false;
    ELSIF kind='wall' AND ((item - ARRAY['key','type','segment_handles']) <> '{}'::jsonb OR NOT item ?& ARRAY['key','type','segment_handles'] OR jsonb_typeof(item->'segment_handles') <> 'array' OR jsonb_array_length(item->'segment_handles') < 1 OR jsonb_path_exists(item, '$.segment_handles[*] ? (@.type() != "string")') OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(item->'segment_handles') handle WHERE length(handle) NOT BETWEEN 1 AND 512) OR (SELECT count(*) FROM jsonb_array_elements_text(item->'segment_handles')) <> (SELECT count(DISTINCT handle) FROM jsonb_array_elements_text(item->'segment_handles') handle)) THEN RETURN false;
    ELSIF kind='opening' AND ((item - ARRAY['key','type','wall_key','opening_type','boundary_handles','dimension_handle']) <> '{}'::jsonb OR NOT item ?& ARRAY['key','type','wall_key','opening_type','boundary_handles','dimension_handle'] OR item->>'opening_type' NOT IN ('door','window','gate','other') OR jsonb_typeof(item->'wall_key') <> 'string' OR length(item->>'wall_key') NOT BETWEEN 1 AND 512 OR jsonb_typeof(item->'dimension_handle') <> 'string' OR length(item->>'dimension_handle') NOT BETWEEN 1 AND 512 OR jsonb_typeof(item->'boundary_handles') <> 'array' OR jsonb_array_length(item->'boundary_handles') <> 2 OR jsonb_path_exists(item, '$.boundary_handles[*] ? (@.type() != "string")') OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(item->'boundary_handles') handle WHERE length(handle) NOT BETWEEN 1 AND 512) OR (item->'boundary_handles'->>0)=(item->'boundary_handles'->>1)) THEN RETURN false; END IF;
  END LOOP;
  IF (SELECT count(*) FROM jsonb_array_elements(payload->'elements')) <> (SELECT count(DISTINCT value->>'key') FROM jsonb_array_elements(payload->'elements')) THEN RETURN false; END IF;
  IF EXISTS (
    SELECT 1 FROM (
      SELECT element->>'boundary_handle' handle FROM jsonb_array_elements(payload->'elements') element WHERE element->>'type'='room'
      UNION ALL
      SELECT handle FROM jsonb_array_elements(payload->'elements') element CROSS JOIN LATERAL jsonb_array_elements_text(element->'segment_handles') handle WHERE element->>'type'='wall'
    ) ownership GROUP BY handle HAVING count(*) > 1
  ) THEN RETURN false; END IF;
  IF EXISTS (
    SELECT 1 FROM jsonb_array_elements(payload->'elements') opening
    WHERE opening->>'type'='opening' AND NOT EXISTS (
      SELECT 1 FROM jsonb_array_elements(payload->'elements') wall
      WHERE wall->>'type'='wall' AND wall->>'key'=opening->>'wall_key'
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(opening->'boundary_handles') handle WHERE NOT (wall->'segment_handles' ? handle))
    )
  ) THEN RETURN false; END IF;
  RETURN true;
END $$;
ALTER TABLE estimate_generation_geometry_confirmations ADD CONSTRAINT eg_geometry_confirmation_versions_ck CHECK (previous_input_version ~ '^sha256:[a-f0-9]{64}$' AND previous_content_version ~ '^sha256:[a-f0-9]{64}$' AND confirmed_input_version ~ '^sha256:[a-f0-9]{64}$' AND confirmed_content_version ~ '^sha256:[a-f0-9]{64}$'), ADD CONSTRAINT eg_geometry_confirmation_source_ck CHECK (source_class = 'user_geometry_confirmation' AND reviewer_ref ~ '^user:[1-9][0-9]*$'), ADD CONSTRAINT eg_geometry_confirmation_payload_ck CHECK (eg_geometry_confirmation_semantic_valid_v1(semantic_payload));
CREATE FUNCTION eg_geometry_confirmation_guard_v1() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous_model record; confirmed_model record;
BEGIN
  IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'estimate_generation.geometry_confirmation_immutable'; END IF;
  SELECT input_version, content_version INTO previous_model FROM estimate_generation_building_models WHERE id=NEW.previous_building_model_id AND organization_id=NEW.organization_id AND project_id=NEW.project_id AND session_id=NEW.session_id;
  SELECT input_version, content_version INTO confirmed_model FROM estimate_generation_building_models WHERE id=NEW.confirmed_building_model_id AND organization_id=NEW.organization_id AND project_id=NEW.project_id AND session_id=NEW.session_id;
  IF previous_model IS NULL OR confirmed_model IS NULL OR previous_model.input_version<>NEW.previous_input_version OR previous_model.content_version<>NEW.previous_content_version OR confirmed_model.input_version<>NEW.confirmed_input_version OR confirmed_model.content_version<>NEW.confirmed_content_version THEN RAISE EXCEPTION 'estimate_generation.geometry_confirmation_lineage_invalid'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_geometry_confirmation_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_geometry_confirmations FOR EACH ROW EXECUTE FUNCTION eg_geometry_confirmation_guard_v1();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS eg_geometry_confirmation_guard_v1() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS eg_geometry_confirmation_semantic_valid_v1(jsonb) CASCADE');
        }
        Schema::dropIfExists('estimate_generation_geometry_confirmations');
    }
};
