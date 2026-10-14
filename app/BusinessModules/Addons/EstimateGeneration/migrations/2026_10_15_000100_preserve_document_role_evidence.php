<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = "ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key','native_reference']";

    private const AFTER = "ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key','native_reference','document_role']";

    private const CHECK = <<<'SQL'
    IF NEW.locator ? 'document_role' AND NOT COALESCE(
        NEW.type IN ('source_fact','extracted','measured')
        AND jsonb_typeof(NEW.locator->'document_role') = 'string'
        AND NEW.locator->>'document_role' IN ('plan','facade','room_schedule','explication','section','equipment_plan','equipment_scheme','equipment_specification','finish_schedule'), false
    ) THEN RAISE EXCEPTION 'estimate_generation.evidence_document_role_invalid'; END IF;

SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Document role evidence requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        if (is_string($definition) && substr_count($definition, self::AFTER) === 1 && substr_count($definition, self::CHECK) === 1) {
            return;
        }
        if (! is_string($definition) || substr_count($definition, self::BEFORE) !== 1 || substr_count($definition, "BEGIN\n") !== 1) {
            throw new RuntimeException('document_role_evidence_guard_contract_mismatch');
        }
        DB::unprepared(str_replace([self::BEFORE, "BEGIN\n"], [self::AFTER, "BEGIN\n".self::CHECK], $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Document classification provenance history is forward-only.');
    }
};
