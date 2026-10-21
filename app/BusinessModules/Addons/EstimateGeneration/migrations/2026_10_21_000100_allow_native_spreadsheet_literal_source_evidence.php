<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Native spreadsheet literal evidence requires PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_evidence_semantic_guard()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
IF NEW.locator ? 'fact_source' AND NOT eg_fact_source_locator_valid(NEW.locator->'fact_source') THEN RAISE EXCEPTION 'estimate_generation.fact_source_locator_invalid'; END IF;
    IF NEW.locator ? 'document_role' AND NOT COALESCE(
        NEW.type IN ('source_fact','extracted','measured')
        AND jsonb_typeof(NEW.locator->'document_role') = 'string'
        AND NEW.locator->>'document_role' IN ('plan','facade','room_schedule','explication','section','equipment_plan','equipment_scheme','equipment_specification','finish_schedule'), false
    ) THEN RAISE EXCEPTION 'estimate_generation.evidence_document_role_invalid'; END IF;
    IF NEW.locator ? 'native_reference' AND NOT COALESCE(
        jsonb_typeof(NEW.locator->'native_reference') = 'string'
        AND octet_length(NEW.locator->>'native_reference') <= 500
        AND NEW.locator->>'native_reference' ~ '^(xlsx:sheet:[^\r\n]+![A-Z]{1,3}[1-9][0-9]{0,6}|cad:(dimension|entity):[A-Za-z0-9_.:/-]+|input_payload\.[a-z][a-z0-9_]{0,79})$'
        AND CASE
            WHEN NEW.locator->>'native_reference' LIKE 'xlsx:%' THEN char_length(regexp_replace(NEW.locator->>'native_reference', '^xlsx:sheet:|![A-Z]{1,3}[1-9][0-9]{0,6}$', '', 'g')) BETWEEN 1 AND 400
            WHEN NEW.locator->>'native_reference' LIKE 'cad:%' THEN char_length(regexp_replace(NEW.locator->>'native_reference', '^cad:(dimension|entity):', '')) BETWEEN 1 AND 300
            ELSE true
        END, false
    ) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    IF octet_length(NEW.locator::text) > 65536 OR octet_length(NEW.value::text) > 65536 THEN RAISE EXCEPTION 'estimate_generation.evidence_json_too_large'; END IF;
    IF ((NEW.source_version ~ '^(catalog|price):' AND split_part(NEW.source_version, ':', 2) ~ '-' AND split_part(NEW.source_version, ':', 2) !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$') OR (NEW.producer_version ~ '^(catalog|price):' AND split_part(NEW.producer_version, ':', 2) ~ '-' AND split_part(NEW.producer_version, ':', 2) !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$')) THEN RAISE EXCEPTION 'estimate_generation.evidence_version_invalid'; END IF;
    IF (NEW.source_type = 'user_input' AND NEW.source_ref ~ '-' AND split_part(NEW.source_ref, ':', 2) !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$') OR (NEW.source_type = 'price_snapshot' AND NEW.source_ref ~ '^price:regional:.*-' AND split_part(NEW.source_ref, ':', 3) !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$') THEN RAISE EXCEPTION 'estimate_generation.evidence_source_ref_invalid'; END IF;
    IF NEW.producer_name NOT IN ('pdf_geometry','ocr_fact_extractor','drawing_analyzer','native_numeric_parser','scope_inference','work_planner','normative_matcher','price_resolver','user_input_normalizer','pipeline','test','contract') THEN RAISE EXCEPTION 'estimate_generation.evidence_producer_invalid'; END IF;
    IF NEW.source_version !~ '^(sha256:[a-f0-9]{64}|manifest:[a-f0-9]{64}|(extractor|model|pipeline|semver):v[0-9]+(\.[0-9]+){0,3}|(catalog|price):([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|(contract|test):[a-f0-9]{6,32}|fsnb:[0-9]{4}(\.[0-9]+)?|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' OR NEW.producer_version !~ '^(sha256:[a-f0-9]{64}|manifest:[a-f0-9]{64}|(extractor|model|pipeline|semver):v[0-9]+(\.[0-9]+){0,3}|(catalog|price):([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|(contract|test):[a-f0-9]{6,32}|fsnb:[0-9]{4}(\.[0-9]+)?|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_version_invalid'; END IF;
    IF NOT ((NEW.source_type IN ('document','document_unit') AND NEW.source_ref ~ '^document:[1-9][0-9]*$') OR (NEW.source_type = 'page_region' AND NEW.source_ref ~ '^document:[1-9][0-9]*/page:[1-9][0-9]*/region:([1-9][0-9]*|[a-f0-9]{64})$') OR (NEW.source_type = 'user_input' AND NEW.source_ref ~ '^input:([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$') OR (NEW.source_type = 'catalog_norm' AND NEW.source_ref ~ '^norm:((gesn|fer):[0-9]+(-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$') OR (NEW.source_type = 'price_snapshot' AND NEW.source_ref ~ '^price:(fgiscs:[0-9]{4}-(0[1-9]|1[0-2])|regional:([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}))$') OR (NEW.source_type = 'pipeline' AND NEW.source_ref ~ '^pipeline:(understand_object|infer_scope|quantity_takeoff|decompose|match_normatives|price|validate|persist)$')) THEN RAISE EXCEPTION 'estimate_generation.evidence_source_ref_invalid'; END IF;
    IF NEW.type IN ('source_fact','extracted','measured') THEN
        IF NEW.locator - (ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key','native_reference','document_role'] || ARRAY['fact_source']) <> '{}'::jsonb OR NOT (NEW.locator ? 'document_id' OR NEW.locator ? 'source_key') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF (NEW.locator ? 'document_id' AND (jsonb_typeof(NEW.locator->'document_id') <> 'number' OR NEW.locator->>'document_id' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'unit_index' AND (jsonb_typeof(NEW.locator->'unit_index') <> 'number' OR NEW.locator->>'unit_index' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'page' AND (jsonb_typeof(NEW.locator->'page') <> 'number' OR NEW.locator->>'page' !~ '^[1-9][0-9]*$')) OR (NEW.locator ? 'sheet' AND (jsonb_typeof(NEW.locator->'sheet') <> 'number' OR NEW.locator->>'sheet' !~ '^[1-9][0-9]*$')) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF (NEW.locator ? 'document_id' AND (NEW.locator->>'document_id')::numeric > 1000000) OR (NEW.locator ? 'unit_index' AND (NEW.locator->>'unit_index')::numeric > 1000000) OR (NEW.locator ? 'page' AND (NEW.locator->>'page')::numeric > 1000000) OR (NEW.locator ? 'sheet' AND (NEW.locator->>'sheet')::numeric > 1000000) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'bbox' AND (jsonb_typeof(NEW.locator->'bbox') <> 'array' OR jsonb_array_length(NEW.locator->'bbox') <> 4 OR EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.locator->'bbox') coordinate WHERE jsonb_typeof(coordinate) <> 'number' OR (coordinate #>> '{}')::numeric < 0 OR (coordinate #>> '{}')::numeric > 1000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'unit_type' AND NEW.locator->>'unit_type' NOT IN ('pdf_page','spreadsheet_sheet','raster_image','sketch','cad_drawing','text_page') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'region_key' AND NEW.locator->>'region_key' !~ '^region:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'element_key' AND NEW.locator->>'element_key' !~ '^element:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
        IF NEW.locator ? 'source_key' AND NEW.locator->>'source_key' !~ '^source:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    ELSIF NEW.type = 'inferred' THEN
        IF NEW.locator - ARRAY['inference_key','item_key'] <> '{}'::jsonb OR NEW.locator->>'inference_key' !~ '^inference:([1-9][0-9]*|[a-f0-9]{64})$' OR (NEW.locator ? 'item_key' AND NEW.locator->>'item_key' !~ '^item:([1-9][0-9]*|[a-f0-9]{64})$') THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    ELSE
        IF NEW.locator - ARRAY['item_key'] <> '{}'::jsonb OR NEW.locator->>'item_key' !~ '^item:([1-9][0-9]*|[a-f0-9]{64})$' THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;
    END IF;
    IF NEW.type = 'source_fact' AND NEW.value ? 'native_literal_source' THEN
        DECLARE binding jsonb := NEW.value->'native_literal_source'; field text; native_unit record;
        BEGIN
            IF jsonb_typeof(binding) IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_invalid'; END IF;
            IF NOT binding ?& ARRAY['schema_version','producer','artifact_sha256','document_id','unit_id','cell','entity_key','fact_type','value_hash','organization_id','project_id','session_id','source_version']
                OR binding - ARRAY['schema_version','producer','artifact_sha256','document_id','unit_id','cell','entity_key','fact_type','value_hash','organization_id','project_id','session_id','source_version'] <> '{}'::jsonb
                THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_invalid'; END IF;
            IF jsonb_typeof(binding->'schema_version') IS DISTINCT FROM 'number' OR binding->>'schema_version' IS DISTINCT FROM '1'
                OR jsonb_typeof(binding->'producer') IS DISTINCT FROM 'string' OR binding->>'producer' IS DISTINCT FROM 'native_spreadsheet_literal:v1'
                OR NOT COALESCE(jsonb_typeof(binding->'artifact_sha256') = 'string' AND binding->>'artifact_sha256' ~ '^sha256:[a-f0-9]{64}$', false)
                OR NOT COALESCE(jsonb_typeof(binding->'source_version') = 'string' AND binding->>'source_version' ~ '^sha256:[a-f0-9]{64}$', false)
                OR NOT COALESCE(jsonb_typeof(binding->'value_hash') = 'string' AND binding->>'value_hash' ~ '^[a-f0-9]{64}$', false)
                OR NOT COALESCE(jsonb_typeof(binding->'cell') = 'string' AND binding->>'cell' ~ '^[A-Z]{1,3}[1-9][0-9]{0,6}$', false)
                OR NOT COALESCE(jsonb_typeof(binding->'entity_key') = 'string' AND char_length(binding->>'entity_key') BETWEEN 1 AND 120 AND binding->>'entity_key' ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]*$', false)
                OR NOT COALESCE(jsonb_typeof(binding->'fact_type') = 'string' AND char_length(binding->>'fact_type') BETWEEN 1 AND 120 AND binding->>'fact_type' ~ '^[a-z][a-z0-9_]*$', false)
                THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_invalid'; END IF;
            FOREACH field IN ARRAY ARRAY['document_id','unit_id','organization_id','project_id','session_id'] LOOP
                IF jsonb_typeof(binding->field) IS DISTINCT FROM 'number'
                    OR NOT COALESCE(binding->>field ~ '^[1-9][0-9]*$', false)
                    THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_invalid'; END IF;
                IF (binding->>field)::numeric > 9223372036854775807 THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_invalid'; END IF;
            END LOOP;
            IF NEW.source_type IS DISTINCT FROM 'document' OR NEW.producer_name IS DISTINCT FROM 'drawing_analyzer'
                OR binding->>'organization_id' IS DISTINCT FROM NEW.organization_id::text
                OR binding->>'project_id' IS DISTINCT FROM NEW.project_id::text
                OR binding->>'session_id' IS DISTINCT FROM NEW.session_id::text
                OR binding->>'source_version' IS DISTINCT FROM NEW.source_version
                OR NEW.source_ref IS DISTINCT FROM 'document:'||(binding->>'document_id')
                OR NEW.locator->>'document_id' IS DISTINCT FROM binding->>'document_id'
                OR NEW.locator->>'unit_type' IS DISTINCT FROM 'spreadsheet_sheet'
                OR NOT COALESCE(NEW.locator->>'native_reference' LIKE 'xlsx:sheet:%!'||(binding->>'cell'), false)
                OR NEW.value->>'fact_value' IS DISTINCT FROM 'material:'||(binding->>'value_hash')
                OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_binding_invalid'; END IF;
            SELECT unit.unit_index, unit.unit_type, unit.locator INTO native_unit
                FROM estimate_generation_processing_units unit
                JOIN estimate_generation_documents document ON document.id = unit.document_id
                    AND document.organization_id = unit.organization_id AND document.project_id = unit.project_id AND document.session_id = unit.session_id
                WHERE unit.id = (binding->>'unit_id')::bigint
                    AND unit.organization_id = NEW.organization_id AND unit.project_id = NEW.project_id AND unit.session_id = NEW.session_id
                    AND unit.document_id = (binding->>'document_id')::bigint AND unit.source_version = NEW.source_version
                    AND unit.status IN ('running','completed') AND document.source_version = NEW.source_version;
            IF NOT FOUND OR native_unit.unit_type IS DISTINCT FROM 'spreadsheet_sheet'
                OR native_unit.unit_index::text IS DISTINCT FROM NEW.locator->>'unit_index'
                OR native_unit.locator->>'source_version' IS DISTINCT FROM NEW.source_version
                OR native_unit.locator->>'artifact_sha256' IS DISTINCT FROM binding->>'artifact_sha256'
                OR native_unit.locator->>'source_kind' IS DISTINCT FROM 'spreadsheet'
                OR native_unit.locator->>'artifact_kind' IS DISTINCT FROM 'spreadsheet_sheet'
                OR native_unit.locator->'artifact_schema_version' IS DISTINCT FROM '1'::jsonb
                THEN RAISE EXCEPTION 'estimate_generation.native_literal_source_binding_invalid'; END IF;
        END;
    END IF;

    IF NEW.type IN ('source_fact','extracted') THEN
        IF NEW.value - (CASE WHEN NEW.type = 'source_fact' THEN ARRAY['fact_key','fact_value','unit','native_literal_source'] ELSE ARRAY['field_key','field_value','unit'] END) <> '{}'::jsonb THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
        IF NOT NEW.value ? (CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) OR NOT NEW.value ? (CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) OR jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) NOT IN ('number','boolean','string') OR (NEW.value ? 'unit' AND NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
        IF COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END), '') NOT IN ('wall_length','wall_height','area','perimeter','opening_width','opening_height','opening_count','room_area','room_type_code','floor_count','floor_height','roof_area','roof_slope','material_code','quantity','element_type_code','elevation','evaluation_choice','room_name','room_number','room_floor','room_zone') THEN RAISE EXCEPTION 'estimate_generation.evidence_attribute_invalid'; END IF;
        IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'evaluation_choice' THEN
    IF NEW.type <> 'source_fact' OR NEW.source_type <> 'user_input' OR NEW.producer_name <> 'user_input_normalizer'
      OR jsonb_typeof(NEW.value->'fact_value') <> 'string' OR NEW.value->>'fact_value' !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.evaluation_choice_evidence_invalid'; END IF;
END IF;
IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) IN ('room_floor','room_zone') THEN
    IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) <> 'string'
      OR NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.room_location_evidence_invalid'; END IF;
END IF;
IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) IN ('room_name','room_number') THEN
    IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) <> 'string'
      OR NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.room_identity_evidence_invalid'; END IF;
END IF;
IF COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') NOT IN ('room_name','room_number')
AND COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') NOT IN ('room_floor','room_zone')
AND COALESCE(NEW.value->>'fact_key','') <> 'evaluation_choice' AND jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric BETWEEN 0 AND 1000000000000) OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'elevation' AND NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^-0(\.0+)?$' AND abs((NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric) <= 1000000000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
        IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'number' AND ((NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric < 0 OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric > 1000000000000) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'measured' THEN IF NEW.value - ARRAY['quantity','unit','method'] <> '{}'::jsonb OR NOT NEW.value ? 'quantity' OR NOT NEW.value ? 'unit' OR NOT (jsonb_typeof(NEW.value->'quantity') = 'number' OR (NEW.source_type = 'user_input' AND NEW.producer_name = 'user_input_normalizer' AND jsonb_typeof(NEW.value->'quantity') = 'string' AND NEW.value->>'quantity' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) OR (NEW.value->>'quantity')::numeric NOT BETWEEN 0 AND 1000000000000 OR NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h') OR (NEW.value ? 'method' AND NEW.value->>'method' NOT IN ('geometry','ocr','calculated','user_confirmed')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'inferred' THEN IF NEW.value - ARRAY['result_code','confidence_band'] <> '{}'::jsonb OR NOT NEW.value ? 'result_code' OR NEW.value->>'result_code' !~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value ? 'confidence_band' AND NEW.value->>'confidence_band' NOT IN ('low','medium','high')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'work_item' THEN IF NEW.value - ARRAY['work_code','quantity','unit'] <> '{}'::jsonb OR NOT NEW.value ? 'work_code' OR NEW.value->>'work_code' !~ '^work_type:([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})$' OR (NEW.value ? 'quantity' AND (jsonb_typeof(NEW.value->'quantity') <> 'number' OR (NEW.value->>'quantity')::numeric NOT BETWEEN 0 AND 1000000000000)) OR (NEW.value ? 'unit' AND NEW.value->>'unit' NOT IN ('m','m2','m3','pcs','kg','t','h')) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'normative_match' THEN IF NEW.value - ARRAY['norm_key','score','dataset_version'] <> '{}'::jsonb OR NOT (NEW.value ?& ARRAY['norm_key','score','dataset_version']) OR jsonb_typeof(NEW.value->'score') <> 'number' OR (NEW.value->>'score')::numeric NOT BETWEEN 0 AND 1 OR NEW.value->>'norm_key' !~ '^((gesn|fer):[0-9]+(-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$' OR NEW.value->>'dataset_version' !~ '^(fsnb:[0-9]{4}(\.[0-9]+)?|catalog:([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    ELSIF NEW.type = 'price' THEN IF NEW.value - ARRAY['amount','currency','price_version','region_code'] <> '{}'::jsonb OR NOT (NEW.value ?& ARRAY['amount','currency','price_version']) OR jsonb_typeof(NEW.value->'amount') <> 'number' OR (NEW.value->>'amount')::numeric NOT BETWEEN 0 AND 1000000000000 OR NEW.value->>'currency' NOT IN ('RUB','USD','EUR') OR NEW.value->>'price_version' !~ '^(price:([1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|fgiscs:[0-9]{4}-(0[1-9]|1[0-2]))$' OR (NEW.value ? 'region_code' AND NEW.value->>'region_code' !~ '^[0-9]{1,6}$') THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
    END IF;
    RETURN NEW;
END; $function$
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Native spreadsheet literal evidence history is forward-only.');
    }
};
