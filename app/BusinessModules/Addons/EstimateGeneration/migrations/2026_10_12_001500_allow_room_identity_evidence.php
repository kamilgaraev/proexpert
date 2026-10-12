<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Room identity evidence requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $attribute = "'element_type_code','elevation','evaluation_choice'";
        $stringGuard = "IF COALESCE(NEW.value->>'fact_key','') <> 'evaluation_choice' AND jsonb_typeof";
        if (! is_string($definition) || substr_count($definition, $attribute) !== 1 || substr_count($definition, $stringGuard) !== 1) {
            throw new RuntimeException('Room identity evidence guard shape is unexpected.');
        }
        $guard = <<<'SQL'
IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'room_name' THEN
    IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) <> 'string'
      OR NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.room_identity_evidence_invalid'; END IF;
END IF;
IF COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') <> 'room_name'
AND COALESCE(NEW.value->>'fact_key','') <> 'evaluation_choice' AND jsonb_typeof
SQL;
        DB::unprepared(str_replace([$attribute, $stringGuard], [$attribute.",'room_name'", $guard], $definition));
        $entityDefinition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        if (! is_string($entityDefinition) || substr_count($entityDefinition, "BEGIN\n") !== 1) {
            throw new RuntimeException('Room identity entity guard shape is unexpected.');
        }
        $identityGuard = <<<'SQL'
    IF NEW.entity_kind = 'room' AND NEW.payload->'properties'->>'identity_only' = 'true' THEN
        IF NOT COALESCE(
            jsonb_typeof(NEW.payload) = 'object' AND NEW.payload->>'kind' = 'room'
            AND NEW.payload->>'semantic_type' = 'room' AND NEW.payload->>'key' = NEW.stable_key
            AND NEW.payload - ARRAY['kind','key','semantic_type','name','properties','floor_id','zone_id'] = '{}'::jsonb
            AND jsonb_typeof(NEW.payload->'name') = 'string' AND length(btrim(NEW.payload->>'name')) BETWEEN 1 AND 240
            AND NEW.payload->>'name' !~ '[[:cntrl:]]'
            AND NEW.payload->'properties' = '{"identity_only":true,"requires_confirmation":true}'::jsonb
            AND NOT EXISTS (SELECT 1 FROM jsonb_each(NEW.payload) pair WHERE pair.key IN ('floor_id','zone_id')
                AND (jsonb_typeof(pair.value) <> 'string' OR length(pair.value #>> '{}') NOT BETWEEN 1 AND 191)), false
        ) THEN RAISE EXCEPTION 'estimate_generation.room_identity_payload_invalid'; END IF;
        RETURN NEW;
    END IF;

SQL;
        DB::unprepared(str_replace("BEGIN\n", "BEGIN\n".$identityGuard, $entityDefinition));
    }

    public function down(): void
    {
        throw new RuntimeException('Room identity provenance history is forward-only.');
    }
};
