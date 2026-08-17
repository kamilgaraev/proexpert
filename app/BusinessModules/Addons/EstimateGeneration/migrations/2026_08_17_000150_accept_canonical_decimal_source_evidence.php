<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = <<<'SQL'
IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
SQL;

    private const AFTER = <<<'SQL'
IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) = 'string' AND NOT (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^((material|work_type):([1-9][0-9]*|[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})|room_type:(bedroom|bathroom|kitchen|living|utility|corridor)|roof_type:(flat|pitched|gable|hip)|opening_type:(door|window|gate)|element_type:(wall|floor|roof|opening|room))$' OR (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$' AND (NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END))::numeric BETWEEN 0 AND 1000000000000)) THEN RAISE EXCEPTION 'estimate_generation.evidence_value_invalid'; END IF;
SQL;

    private const ENTITY_BEFORE = [
        "jsonb_typeof(NEW.payload->'area_m2') = 'number' AND (NEW.payload->>'area_m2')::numeric > 0",
        "WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'value') = 'number' AND (NEW.payload->>'value')::numeric > 0 AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))",
        "WHEN 'quantity' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND jsonb_typeof(NEW.payload->'value') = 'number' AND (NEW.payload->>'value')::numeric > 0 AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))",
    ];

    private const ENTITY_AFTER = [
        "((jsonb_typeof(NEW.payload->'area_m2') = 'number' OR (jsonb_typeof(NEW.payload->'area_m2') = 'string' AND NEW.payload->>'area_m2' ~ '^(0|[1-9][0-9]*)(\\.[0-9]{1,4})?$')) AND (NEW.payload->>'area_m2')::numeric > 0 AND (NEW.payload->>'area_m2')::numeric <= 1000000000000)",
        "WHEN 'dimension' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))",
        "WHEN 'quantity' THEN (NEW.payload - ARRAY['kind','key','value','unit','identity','document_role'] = '{}'::jsonb AND ((jsonb_typeof(NEW.payload->'value') = 'number' OR (jsonb_typeof(NEW.payload->'value') = 'string' AND NEW.payload->>'value' ~ '^(0|[1-9][0-9]*)(\\.[0-9]{1,4})?$')) AND (NEW.payload->>'value')::numeric > 0 AND (NEW.payload->>'value')::numeric <= 1000000000000) AND NEW.payload->>'unit' IN ('m','m2','m3','pcs','kg','t','h'))",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replace('eg_evidence_semantic_guard()', self::BEFORE, self::AFTER);
        foreach (self::ENTITY_BEFORE as $index => $before) {
            $this->replace('eg_project_model_entity_payload_guard()', $before, self::ENTITY_AFTER[$index]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(array_keys(self::ENTITY_AFTER)) as $index) {
            $this->replace('eg_project_model_entity_payload_guard()', self::ENTITY_AFTER[$index], self::ENTITY_BEFORE[$index]);
        }
        $this->replace('eg_evidence_semantic_guard()', self::AFTER, self::BEFORE);
    }

    private function replace(string $function, string $before, string $after): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [$function]);
        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new RuntimeException('estimate_generation_evidence_semantic_guard_contract_mismatch');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }
};
