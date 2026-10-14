<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Room number provenance requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        $before = [
            "'element_type_code','elevation','evaluation_choice','room_name'",
            "NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) = 'room_name' THEN",
            "COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') <> 'room_name'",
        ];
        $after = [
            $before[0].",'room_number'",
            "NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END) IN ('room_name','room_number') THEN",
            "COALESCE(NEW.value->>(CASE WHEN NEW.type = 'source_fact' THEN 'fact_key' ELSE 'field_key' END),'') NOT IN ('room_name','room_number')",
        ];
        if (is_string($definition) && substr_count($definition, $after[0]) === 1 && substr_count($definition, $after[1]) === 1 && substr_count($definition, $after[2]) === 1) {
            return;
        }
        foreach ($before as $fragment) {
            if (! is_string($definition) || substr_count($definition, $fragment) !== 1) {
                throw new RuntimeException('room_number_evidence_guard_contract_mismatch');
            }
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Room number provenance history is forward-only.');
    }
};
