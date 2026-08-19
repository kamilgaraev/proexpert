<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROOM_BEFORE = <<<'SQL'
WHEN 'room' THEN (NEW.payload - ARRAY['kind','key','polygon','area_m2','name','identity','document_role'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'polygon') = 'array' AND jsonb_array_length(NEW.payload->'polygon') >= 3 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'polygon') p WHERE jsonb_typeof(p) <> 'array' OR jsonb_array_length(p) <> 2 OR EXISTS (SELECT 1 FROM jsonb_array_elements(p) c WHERE jsonb_typeof(c) <> 'number'))) OR (((jsonb_typeof(NEW.payload->'area_m2') = 'number' OR (jsonb_typeof(NEW.payload->'area_m2') = 'string' AND NEW.payload->>'area_m2' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'area_m2')::numeric > 0 AND (NEW.payload->>'area_m2')::numeric <= 1000000000000)))
SQL;

    private const ROOM_AFTER = <<<'SQL'
WHEN 'room' THEN (NEW.payload - ARRAY['kind','key','polygon','area_m2','name','identity','document_role','semantic_type'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'polygon') = 'array' AND jsonb_array_length(NEW.payload->'polygon') >= 3 AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(NEW.payload->'polygon') p WHERE jsonb_typeof(p) <> 'array' OR jsonb_array_length(p) <> 2 OR EXISTS (SELECT 1 FROM jsonb_array_elements(p) c WHERE jsonb_typeof(c) <> 'number'))) OR (((jsonb_typeof(NEW.payload->'area_m2') = 'number' OR (jsonb_typeof(NEW.payload->'area_m2') = 'string' AND NEW.payload->>'area_m2' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'area_m2')::numeric > 0 AND (NEW.payload->>'area_m2')::numeric <= 1000000000000) OR (NEW.payload->>'semantic_type' = 'room' AND NOT (NEW.payload ? 'polygon') AND NOT (NEW.payload ? 'area_m2'))))
SQL;

    private const DIMENSION_BEFORE = <<<'SQL'
WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role','measurement_kind'] = '{}'::jsonb AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h') AND ((NEW.payload->>'measurement_kind' IS NULL AND (jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'elevation' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND NEW.payload->>'value' !~ '^-0(\.0+)?$' AND abs((NEW.payload->>'value')::numeric) <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'level' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.payload->>'value')::numeric <= 1000000000000)))
SQL;

    private const DIMENSION_AFTER = <<<'SQL'
WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role','measurement_kind'] = '{}'::jsonb AND ((NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h') AND ((NEW.payload->>'measurement_kind' IS NULL AND (jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'elevation' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^-?(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND NEW.payload->>'value' !~ '^-0(\.0+)?$' AND abs((NEW.payload->>'value')::numeric) <= 1000000000000) OR (NEW.payload->>'measurement_kind' = 'level' AND jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.payload->>'value')::numeric <= 1000000000000))) OR (NEW.payload->>'measurement_kind' IN ('area','dimension_chain','elevation','level') AND NOT (NEW.payload ? 'value') AND NOT (NEW.payload ? 'unit'))))
SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replace(self::ROOM_BEFORE, self::ROOM_AFTER);
        $this->replace(self::DIMENSION_BEFORE, self::DIMENSION_AFTER);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replace(self::DIMENSION_AFTER, self::DIMENSION_BEFORE);
        $this->replace(self::ROOM_AFTER, self::ROOM_BEFORE);
    }

    private function replace(string $before, string $after): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new RuntimeException('estimate_generation_fact_independent_entity_guard_contract_mismatch');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }
};
