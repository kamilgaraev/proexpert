<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = "ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key','native_reference','document_role']";

    private const CHECK = "IF NEW.locator ? 'fact_source' AND NOT eg_fact_source_locator_valid(NEW.locator->'fact_source') THEN RAISE EXCEPTION 'estimate_generation.fact_source_locator_invalid'; END IF;";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Fact source locators require PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_fact_source_locator_valid(source jsonb) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE point jsonb; coordinate jsonb; seen text[] := ARRAY[]::text[]; signature text;
BEGIN
    IF jsonb_typeof(source) IS DISTINCT FROM 'object' OR NOT source ?& ARRAY['kind','coordinate_space','value']
      OR source - ARRAY['kind','coordinate_space','value'] <> '{}'::jsonb THEN RETURN false; END IF;
    IF source->>'kind' = 'native' THEN
      RETURN COALESCE(source->>'coordinate_space' = 'native' AND jsonb_typeof(source->'value') = 'string'
        AND char_length(source->>'value') <= 240
        AND source->>'value' ~ '^(pdf|image|cad|xlsx):.{1,220}$'
        AND source->>'value' !~ '[[:cntrl:]]' AND strpos(source->>'value', chr(92)) = 0, false);
    END IF;
    IF source->>'kind' IS DISTINCT FROM 'polygon' OR source->>'coordinate_space' IS DISTINCT FROM 'normalized_source_v1'
      OR jsonb_typeof(source->'value') IS DISTINCT FROM 'array' THEN RETURN false; END IF;
    IF jsonb_array_length(source->'value') NOT BETWEEN 2 AND 64 THEN RETURN false; END IF;
    FOR point IN SELECT value FROM jsonb_array_elements(source->'value') LOOP
      IF jsonb_typeof(point) IS DISTINCT FROM 'array' OR jsonb_array_length(point) <> 2 THEN RETURN false; END IF;
      FOR coordinate IN SELECT value FROM jsonb_array_elements(point) LOOP
        IF jsonb_typeof(coordinate) IS DISTINCT FROM 'number' THEN RETURN false; END IF;
        IF coordinate::text::numeric NOT BETWEEN 0 AND 1 THEN RETURN false; END IF;
      END LOOP;
      signature := round((point->>0)::numeric, 12)::text || ':' || round((point->>1)::numeric, 12)::text;
      IF signature = ANY(seen) THEN RETURN false; END IF;
      seen := array_append(seen, signature);
    END LOOP;
    RETURN true;
EXCEPTION WHEN OTHERS THEN RETURN false;
END; $$;
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $after = '('.self::BEFORE." || ARRAY['fact_source'])";
        if (is_string($definition) && substr_count($definition, $after) === 1 && substr_count($definition, self::CHECK) === 1) {
            return;
        }
        if (! is_string($definition) || substr_count($definition, self::BEFORE) !== 1 || substr_count($definition, "BEGIN\n") !== 1) {
            throw new RuntimeException('fact_source_locator_guard_contract_mismatch');
        }
        DB::unprepared(str_replace([self::BEFORE, "BEGIN\n"], [$after, "BEGIN\n".self::CHECK."\n"], $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Exact source provenance history is forward-only.');
    }
};
