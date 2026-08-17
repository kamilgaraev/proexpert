<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ATTRIBUTE_BEFORE = <<<'SQL'
IF COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END), '') NOT IN ('wall_length','wall_height','area','perimeter','opening_width','opening_height','opening_count','room_area','room_type_code','floor_count','floor_height','roof_area','roof_slope','material_code','quantity','element_type_code') THEN RAISE EXCEPTION 'estimate_generation.evidence_attribute_invalid'; END IF;
SQL;

    private const ATTRIBUTE_AFTER = <<<'SQL'
IF COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END), '') NOT IN ('wall_length','wall_height','area','perimeter','opening_width','opening_height','opening_count','room_area','room_type_code','floor_count','floor_height','roof_area','roof_slope','material_code','quantity','element_type_code','elevation') THEN RAISE EXCEPTION 'estimate_generation.evidence_attribute_invalid'; END IF;
SQL;

    private const EVIDENCE_BEFORE = <<<'SQL'
IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric BETWEEN 0 AND 1000000000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
SQL;

    private const EVIDENCE_AFTER = <<<'SQL'
IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric BETWEEN 0 AND 1000000000000) OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'elevation' AND NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^-0(\.0+)?$' AND abs((NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric) <= 1000000000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
SQL;

    private const ENTITY_BEFORE = <<<'SQL'
WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))
SQL;

    private const ENTITY_AFTER = <<<'SQL'
WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role','measurement_kind'] = '{}'::jsonb AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h') AND ((NEW.payload->>'measurement_kind' IS NULL AND (jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'elevation' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND NEW.payload->>'value' !~ '^-0(\.0+)?$' AND abs((NEW.payload->>'value')::numeric) <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'level' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.payload->>'value')::numeric <= 1000000000000)))
SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replace('eg_evidence_semantic_guard()', self::ATTRIBUTE_BEFORE, self::ATTRIBUTE_AFTER);
        $this->replace('eg_evidence_semantic_guard()', self::EVIDENCE_BEFORE, self::EVIDENCE_AFTER);
        $this->replace('eg_project_model_entity_payload_guard()', self::ENTITY_BEFORE, self::ENTITY_AFTER);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replace('eg_project_model_entity_payload_guard()', self::ENTITY_AFTER, self::ENTITY_BEFORE);
        $this->replace('eg_evidence_semantic_guard()', self::EVIDENCE_AFTER, self::EVIDENCE_BEFORE);
        $this->replace('eg_evidence_semantic_guard()', self::ATTRIBUTE_AFTER, self::ATTRIBUTE_BEFORE);
    }

    private function replace(string $function, string $before, string $after): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]);
        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new RuntimeException('estimate_generation_signed_elevation_guard_contract_mismatch');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }
};
