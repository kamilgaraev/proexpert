<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BRANCH = <<<'SQL'
    IF NEW.payload->>'semantic_type' IN ('room','wall','opening','site','roof','roof_facet','roof_opening','quantity')
        AND NOT (NEW.payload ?| ARRAY['polygon','area_m2','start','end','width_m','height_m','value','unit']) THEN
        IF NOT COALESCE(
            jsonb_typeof(NEW.payload) = 'object'
            AND NEW.payload->>'kind' = NEW.entity_kind
            AND NEW.payload->>'key' = NEW.stable_key
            AND octet_length(NEW.payload::text) <= 1048576
            AND NEW.payload - ARRAY['kind','key','semantic_type','identity','document_role','floor_id','zone_id','room_id','wall_id','roof_id','geometry_identity'] = '{}'::jsonb
            AND CASE NEW.payload->>'semantic_type'
                WHEN 'room' THEN NEW.entity_kind = 'room'
                WHEN 'wall' THEN NEW.entity_kind = 'wall'
                WHEN 'opening' THEN NEW.entity_kind = 'opening'
                ELSE NEW.entity_kind = 'quantity'
            END
            AND NOT EXISTS (
                SELECT 1 FROM jsonb_each(NEW.payload) pair
                WHERE pair.key IN ('floor_id','zone_id','room_id','wall_id','roof_id','geometry_identity')
                AND (jsonb_typeof(pair.value) <> 'string' OR length(pair.value #>> '{}') NOT BETWEEN 1 AND 191)
            ), false
        ) THEN
            RAISE EXCEPTION 'estimate_generation.project_model_entity_payload_invalid';
        END IF;
        RETURN NEW;
    END IF;

SQL;

    public function up(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        if (! is_string($definition) || str_contains($definition, self::BRANCH) || substr_count($definition, "BEGIN\n") !== 1) {
            throw new RuntimeException('fact_based_geometry_entity_guard_contract_mismatch');
        }
        DB::unprepared(str_replace("BEGIN\n", "BEGIN\n".self::BRANCH, $definition));
    }

    public function down(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        if (! is_string($definition) || substr_count($definition, self::BRANCH) !== 1) {
            throw new RuntimeException('fact_based_geometry_entity_guard_contract_mismatch');
        }
        DB::unprepared(str_replace(self::BRANCH, '', $definition));
    }
};
