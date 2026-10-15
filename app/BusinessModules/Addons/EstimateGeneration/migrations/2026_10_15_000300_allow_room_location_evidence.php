<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Room location provenance requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $before = [
            "'element_type_code','elevation','evaluation_choice','room_name','room_number'",
            "IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) IN ('room_name','room_number') THEN",
            "COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') NOT IN ('room_name','room_number')",
        ];
        $locationGuard = <<<'SQL'
IF NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) IN ('room_floor','room_zone') THEN
    IF jsonb_typeof(NEW.value->(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END)) <> 'string'
      OR NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_value' ELSE 'field_value' END) !~ '^[a-f0-9]{64}$'
      OR NEW.value ? 'unit' THEN RAISE EXCEPTION 'estimate_generation.room_location_evidence_invalid'; END IF;
END IF;
SQL;
        $after = [
            $before[0].",'room_floor','room_zone'",
            $locationGuard."\n".$before[1],
            $before[2]."\nAND COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') NOT IN ('room_floor','room_zone')",
        ];
        if (is_string($definition) && substr_count($definition, $after[0]) === 1
            && substr_count($definition, $after[1]) === 1 && substr_count($definition, $after[2]) === 1) {
            return;
        }
        foreach ($before as $fragment) {
            if (! is_string($definition) || substr_count($definition, $fragment) !== 1) {
                throw new RuntimeException('room_location_evidence_guard_contract_mismatch');
            }
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Room location provenance history is forward-only.');
    }
};
