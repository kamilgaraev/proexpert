<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BRANCH = <<<'SQL'
    IF NEW.payload->'properties' ? 'document_semantic_proposal' THEN
        DECLARE
            proposal jsonb := NEW.payload->'properties'->'document_semantic_proposal';
            semantic jsonb;
            pins jsonb;
            fields jsonb := '{}'::jsonb;
            identities jsonb;
            field jsonb;
            field_path text;
            field_limit integer;
            locator jsonb;
            exact_source jsonb;
            source_value jsonb;
            point jsonb;
            coordinate jsonb;
            referenced_paths text[] := ARRAY[]::text[];
            index_value integer;
            normalized_unit text;
            captured_page_id jsonb;
            pin_key text;
        BEGIN
            IF NOT COALESCE(
                jsonb_typeof(NEW.payload) = 'object'
                AND NEW.payload->>'kind' = NEW.entity_kind AND NEW.payload->>'key' = NEW.stable_key
                AND octet_length(NEW.payload::text) <= 1048576
                AND NOT (NEW.payload ?| ARRAY['polygon','area_m2','start','end','thickness_m','width_m','height_m','value','unit'])
                AND jsonb_typeof(proposal) = 'object'
                AND proposal ?& ARRAY['schema_version','status','payload','field_locators','source_pins','proposal_hash']
                AND proposal - ARRAY['schema_version','status','payload','field_locators','source_pins','proposal_hash'] = '{}'::jsonb
                AND jsonb_typeof(proposal->'schema_version') = 'number' AND proposal->>'schema_version' = '1'
                AND proposal->>'status' IS NOT DISTINCT FROM 'proposed'
                AND jsonb_typeof(proposal->'proposal_hash') = 'string' AND proposal->>'proposal_hash' ~ '^[a-f0-9]{64}$'
                AND octet_length(proposal::text) <= 65536
                AND jsonb_typeof(proposal->'payload') = 'object' AND octet_length((proposal->'payload')::text) <= 16384
                AND jsonb_typeof(proposal->'field_locators') = 'object'
                AND jsonb_typeof(proposal->'source_pins') = 'object', false
            ) THEN RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid'; END IF;
            semantic := proposal->'payload';
            pins := proposal->'source_pins';
            IF NOT COALESCE(
                pins ?& ARRAY['organization_id','project_id','session_id','document_id','page_number','processing_unit_id','source_version']
                AND pins - ARRAY['organization_id','project_id','session_id','document_id','page_number','processing_unit_id','source_version'] = '{}'::jsonb
                AND pins->'organization_id' = to_jsonb(NEW.organization_id)
                AND pins->'project_id' = to_jsonb(NEW.project_id) AND pins->'session_id' = to_jsonb(NEW.session_id)
                AND jsonb_typeof(pins->'source_version') = 'string'
                AND char_length(btrim(pins->>'source_version')) BETWEEN 1 AND 255
                AND pins->>'source_version' IS NOT DISTINCT FROM NEW.source_version, false
            ) THEN RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid'; END IF;
            FOR field_path IN SELECT unnest(ARRAY['organization_id','project_id','session_id','document_id','page_number','processing_unit_id']) LOOP
                IF NOT COALESCE(jsonb_typeof(pins->field_path) = 'number' AND pins->>field_path ~ '^[1-9][0-9]{0,18}$', false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                IF (pins->>field_path)::numeric > 9223372036854775807 OR (field_path = 'page_number' AND (pins->>field_path)::numeric > 10000) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
            END LOOP;
            IF NOT COALESCE(jsonb_typeof(semantic->'schemaVersion') = 'number' AND semantic->>'schemaVersion' = '1', false) THEN
                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
            END IF;
            IF semantic->>'kind' = 'equipment_identity' THEN
                IF NOT COALESCE(semantic ?& ARRAY['schemaVersion','kind','identity']
                    AND semantic - ARRAY['schemaVersion','kind','identity'] = '{}'::jsonb
                    AND NEW.entity_kind = 'equipment', false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                identities := semantic->'identity';
                field_path := 'identity';
            ELSIF semantic->>'kind' = 'source_requirement' THEN
                IF NOT COALESCE(semantic ?& ARRAY['schemaVersion','kind','parameter','dimension','comparator','threshold','unit','appliesTo','documentEdition','clause','conditions']
                    AND semantic - ARRAY['schemaVersion','kind','parameter','dimension','comparator','threshold','unit','appliesTo','documentEdition','clause','conditions'] = '{}'::jsonb
                    AND semantic->'dimension' IN ('null'::jsonb, '"length"'::jsonb)
                    AND jsonb_typeof(semantic->'appliesTo') = 'object'
                    AND semantic->'appliesTo' ?& ARRAY['subject','equipmentIdentity']
                    AND (semantic->'appliesTo') - ARRAY['subject','equipmentIdentity'] = '{}'::jsonb, false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                fields := jsonb_build_object('parameter',semantic->'parameter','comparator',semantic->'comparator',
                    'threshold',semantic->'threshold','unit',semantic->'unit','appliesTo.subject',semantic->'appliesTo'->'subject',
                    'documentEdition',semantic->'documentEdition','clause',semantic->'clause');
                IF semantic->'conditions' IS DISTINCT FROM 'null'::jsonb THEN
                    IF jsonb_typeof(semantic->'conditions') IS DISTINCT FROM 'array' THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    IF jsonb_array_length(semantic->'conditions') > 8 THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    FOR index_value IN 0..jsonb_array_length(semantic->'conditions') - 1 LOOP
                        fields := fields || jsonb_build_object('conditions.'||index_value,semantic->'conditions'->index_value);
                    END LOOP;
                END IF;
                identities := semantic->'appliesTo'->'equipmentIdentity';
                field_path := 'appliesTo.equipmentIdentity';
            ELSE
                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
            END IF;
            IF identities IS DISTINCT FROM 'null'::jsonb THEN
                IF NOT COALESCE(jsonb_typeof(identities) = 'object'
                    AND identities ?& ARRAY['manufacturer','model','variant','configuration']
                    AND identities - ARRAY['manufacturer','model','variant','configuration'] = '{}'::jsonb, false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                fields := fields || jsonb_build_object(field_path||'.manufacturer',identities->'manufacturer',field_path||'.model',identities->'model',
                    field_path||'.variant',identities->'variant',field_path||'.configuration',identities->'configuration');
            ELSIF semantic->>'kind' = 'equipment_identity' THEN
                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
            END IF;
            FOR field_path, field IN SELECT key, value FROM jsonb_each(fields) LOOP
                IF NOT COALESCE(jsonb_typeof(field) = 'object' AND field ?& ARRAY['data','evidenceRef','sourcePolygonOrNativeRef']
                    AND field - ARRAY['data','evidenceRef','sourcePolygonOrNativeRef'] = '{}'::jsonb, false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                IF field->'data' = 'null'::jsonb THEN
                    IF field->'evidenceRef' IS DISTINCT FROM 'null'::jsonb OR field->'sourcePolygonOrNativeRef' IS DISTINCT FROM 'null'::jsonb THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    CONTINUE;
                END IF;
                field_limit := CASE WHEN field_path = 'clause' THEN 500 WHEN field_path LIKE 'conditions.%' THEN 300
                    WHEN field_path = 'threshold' THEN 64 WHEN field_path = 'unit' THEN 40 WHEN field_path = 'comparator' THEN 3 ELSE 160 END;
                IF NOT COALESCE(jsonb_typeof(field->'data') = 'string' AND char_length(btrim(field->>'data')) > 0
                    AND char_length(field->>'data') <= field_limit AND field->>'data' !~ '[[:cntrl:]]', false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                IF field_path = 'threshold' THEN
                    IF field->>'data' !~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' OR field->>'data' ~ '^-0(\.0+)?$' THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    IF abs((field->>'data')::numeric) > 1000000000000 THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                ELSIF field_path = 'comparator' AND field->>'data' <> 'gte' THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                ELSIF field_path = 'unit' THEN
                    normalized_unit := replace(replace(replace(replace(lower(btrim(field->>'data')),'²','2'),'³','3'),'^',''),' ','');
                    IF normalized_unit NOT IN ('mm','мм','cm','см','m','м','in','inch','inches','дюйм','дюймы','ft','foot','feet','фут','футы') THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                END IF;
                IF field->'evidenceRef' = 'null'::jsonb AND field->'sourcePolygonOrNativeRef' = 'null'::jsonb THEN CONTINUE; END IF;
                IF NOT COALESCE(jsonb_typeof(field->'evidenceRef') = 'string' AND field->>'evidenceRef' ~ '^[a-z0-9][a-z0-9._:-]{0,79}$', false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                source_value := field->'sourcePolygonOrNativeRef';
                IF jsonb_typeof(source_value) = 'array' THEN
                    IF jsonb_array_length(source_value) NOT BETWEEN 2 AND 64 THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    FOR point IN SELECT value FROM jsonb_array_elements(source_value) LOOP
                        IF jsonb_typeof(point) IS DISTINCT FROM 'array' THEN
                            RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                        END IF;
                        IF jsonb_array_length(point) <> 2 THEN RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid'; END IF;
                        FOR coordinate IN SELECT value FROM jsonb_array_elements(point) LOOP
                            IF jsonb_typeof(coordinate) IS DISTINCT FROM 'number' THEN
                                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                            END IF;
                            IF (coordinate #>> '{}')::numeric NOT BETWEEN 0 AND 1 THEN
                                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                            END IF;
                        END LOOP;
                    END LOOP;
                    IF (SELECT count(DISTINCT value) FROM jsonb_array_elements(source_value)) <> jsonb_array_length(source_value) THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                ELSIF jsonb_typeof(source_value) <> 'string' OR char_length(source_value #>> '{}') > 240
                    OR source_value #>> '{}' !~ '^(pdf|image|cad|xlsx):[^[:cntrl:]]{1,220}$'
                    OR position(chr(92) IN (source_value #>> '{}')) > 0 THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                locator := proposal->'field_locators'->field_path;
                IF NOT COALESCE(jsonb_typeof(locator) = 'object'
                    AND locator ?& ARRAY['page_id','page_number','processing_unit_id','source_version','coordinate_space','organization_id','project_id','session_id','document_id','page','document_role','fact_source']
                    AND locator - ARRAY['page_id','page_number','processing_unit_id','source_version','coordinate_space','organization_id','project_id','session_id','document_id','page','document_role','fact_source','explicit'] = '{}'::jsonb
                    AND locator->'organization_id' = pins->'organization_id' AND locator->'project_id' = pins->'project_id'
                    AND locator->'session_id' = pins->'session_id' AND locator->'document_id' = pins->'document_id'
                    AND locator->'processing_unit_id' = pins->'processing_unit_id' AND locator->'source_version' = pins->'source_version'
                    AND locator->'page_number' = pins->'page_number' AND locator->'page' = pins->'page_number'
                    AND jsonb_typeof(locator->'page_id') = 'number' AND locator->>'page_id' ~ '^[1-9][0-9]{0,18}$'
                    AND jsonb_typeof(locator->'coordinate_space') = 'string'
                    AND locator->>'coordinate_space' IN ('normalized_source_v1','normalized_derivative_v1')
                    AND jsonb_typeof(locator->'document_role') = 'string' AND char_length(locator->>'document_role') BETWEEN 1 AND 120
                    AND (NOT (locator ? 'explicit') OR jsonb_typeof(locator->'explicit') = 'boolean'), false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                FOR pin_key IN SELECT unnest(ARRAY['page_id','page_number','processing_unit_id','organization_id','project_id','session_id','document_id','page']) LOOP
                    IF NOT COALESCE(jsonb_typeof(locator->pin_key) = 'number' AND locator->>pin_key ~ '^[1-9][0-9]{0,18}$', false) THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                    IF (locator->>pin_key)::numeric > 9223372036854775807 THEN
                        RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                    END IF;
                END LOOP;
                IF captured_page_id IS NULL THEN captured_page_id := locator->'page_id'; END IF;
                IF captured_page_id IS DISTINCT FROM locator->'page_id' THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                exact_source := locator->'fact_source';
                IF NOT COALESCE(jsonb_typeof(exact_source) = 'object' AND exact_source ?& ARRAY['kind','coordinate_space','value']
                    AND exact_source - ARRAY['kind','coordinate_space','value'] = '{}'::jsonb AND exact_source->'value' = source_value
                    AND CASE WHEN jsonb_typeof(source_value) = 'array' THEN exact_source->>'kind' = 'polygon'
                        AND exact_source->>'coordinate_space' = 'normalized_source_v1' AND locator->>'coordinate_space' = 'normalized_source_v1'
                        ELSE exact_source->>'kind' = 'native' AND exact_source->>'coordinate_space' = 'native' END, false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                referenced_paths := array_append(referenced_paths,field_path);
            END LOOP;
            IF EXISTS (SELECT 1 FROM jsonb_object_keys(proposal->'field_locators') path WHERE NOT (path = ANY(referenced_paths))) THEN
                RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
            END IF;
            IF NEW.entity_kind = 'equipment' THEN
                IF NOT COALESCE(NEW.payload->'properties' ?& ARRAY['semantic_type','document_context','source_entity_key','document_semantic_proposal']
                    AND (NEW.payload->'properties') - ARRAY['semantic_type','document_context','source_entity_key','document_semantic_proposal'] = '{}'::jsonb
                    AND NEW.payload->'properties'->'document_context' = 'true'::jsonb
                    AND jsonb_typeof(NEW.payload->'properties'->'semantic_type') = 'string'
                    AND char_length(NEW.payload->'properties'->>'semantic_type') BETWEEN 1 AND 120
                    AND jsonb_typeof(NEW.payload->'properties'->'source_entity_key') = 'string'
                    AND char_length(NEW.payload->'properties'->>'source_entity_key') BETWEEN 1 AND 120
                    AND (semantic->>'kind' <> 'equipment_identity' OR NEW.payload->'properties'->>'semantic_type' = 'equipment'), false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
            ELSE
                IF NOT COALESCE(semantic->>'kind' = 'source_requirement'
                    AND NEW.payload - ARRAY['kind','key','semantic_type','identity','document_role','floor_id','zone_id','room_id','wall_id','roof_id','geometry_identity','properties'] = '{}'::jsonb
                    AND (NEW.payload->'properties') - ARRAY['document_semantic_proposal'] = '{}'::jsonb
                    AND CASE NEW.payload->>'semantic_type' WHEN 'room' THEN NEW.entity_kind = 'room'
                        WHEN 'wall' THEN NEW.entity_kind = 'wall' WHEN 'opening' THEN NEW.entity_kind = 'opening'
                        ELSE NEW.entity_kind = 'quantity' AND NEW.payload->>'semantic_type' IN ('site','roof','roof_facet','roof_opening','quantity','foundation_beam','foundation_slab','work_scope') END
                    AND jsonb_typeof(NEW.payload->'identity') = 'object' AND NEW.payload->'identity' ? 'source_entity_key'
                    AND (NEW.payload->'identity') - ARRAY['source_entity_key','label'] = '{}'::jsonb
                    AND jsonb_typeof(NEW.payload->'identity'->'source_entity_key') = 'string'
                    AND char_length(NEW.payload->'identity'->>'source_entity_key') BETWEEN 1 AND 120
                    AND (NOT (NEW.payload->'identity' ? 'label') OR (jsonb_typeof(NEW.payload->'identity'->'label') = 'string'
                        AND char_length(NEW.payload->'identity'->>'label') BETWEEN 1 AND 240))
                    AND (NOT (NEW.payload ? 'document_role') OR (jsonb_typeof(NEW.payload->'document_role') = 'string'
                        AND char_length(NEW.payload->>'document_role') BETWEEN 1 AND 120))
                    AND NOT EXISTS (SELECT 1 FROM jsonb_each(NEW.payload) pair WHERE pair.key IN ('floor_id','zone_id','room_id','wall_id','roof_id','geometry_identity')
                        AND (jsonb_typeof(pair.value) <> 'string' OR length(pair.value #>> '{}') NOT BETWEEN 1 AND 191)), false) THEN
                    RAISE EXCEPTION 'estimate_generation.project_model_document_semantic_proposal_invalid';
                END IF;
                RETURN NEW;
            END IF;
        END;
    END IF;

SQL;

    public function up(): void
    {
        $this->replace(true);
    }

    public function down(): void
    {
        $this->replace(false);
    }

    private function replace(bool $install): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Document semantic proposal entities require PostgreSQL.');
        }
        DB::transaction(function () use ($install): void {
            DB::statement('LOCK TABLE estimate_generation_project_model_entities IN SHARE ROW EXCLUSIVE MODE');
            $definition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
            if (! is_string($definition)
                || ($install ? str_contains($definition, self::BRANCH) || substr_count($definition, "BEGIN\n") !== 1 : substr_count($definition, self::BRANCH) !== 1)) {
                throw new RuntimeException('document_semantic_proposal_entity_guard_contract_mismatch');
            }
            if (! $install && DB::table('estimate_generation_project_model_entities')
                ->whereRaw("jsonb_exists(payload->'properties', 'document_semantic_proposal')")->exists()) {
                throw new RuntimeException('document_semantic_proposal_entities_require_preserved_history');
            }
            DB::unprepared($install ? str_replace("BEGIN\n", "BEGIN\n".self::BRANCH, $definition) : str_replace(self::BRANCH, '', $definition));
        });
    }
};
