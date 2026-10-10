<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = "ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key']";

    private const AFTER = "ARRAY['document_id','unit_type','unit_index','page','sheet','region_key','element_key','bbox','source_key','native_reference']";

    private const CHECK = <<<'SQL'
    IF NEW.locator ? 'native_reference' AND NOT COALESCE(
        jsonb_typeof(NEW.locator->'native_reference') = 'string'
        AND octet_length(NEW.locator->>'native_reference') <= 500
        AND NEW.locator->>'native_reference' ~ '^(xlsx:sheet:[^\r\n]+![A-Z]{1,3}[1-9][0-9]{0,6}|cad:(dimension|entity):[A-Za-z0-9_.:/-]+|input_payload\.[a-z][a-z0-9_]{0,79})$'
        AND CASE
            WHEN NEW.locator->>'native_reference' LIKE 'xlsx:%' THEN char_length(regexp_replace(NEW.locator->>'native_reference', '^xlsx:sheet:|![A-Z]{1,3}[1-9][0-9]{0,6}$', '', 'g')) BETWEEN 1 AND 400
            WHEN NEW.locator->>'native_reference' LIKE 'cad:%' THEN char_length(regexp_replace(NEW.locator->>'native_reference', '^cad:(dimension|entity):', '')) BETWEEN 1 AND 300
            ELSE true
        END, false
    ) THEN RAISE EXCEPTION 'estimate_generation.evidence_locator_invalid'; END IF;

SQL;

    public function up(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        if (! is_string($definition) || substr_count($definition, self::BEFORE) !== 1 || substr_count($definition, "BEGIN\n") !== 1) {
            throw new RuntimeException('native_evidence_reference_guard_contract_mismatch');
        }
        DB::unprepared(str_replace([self::BEFORE, "BEGIN\n"], [self::AFTER, "BEGIN\n".self::CHECK], $definition));
    }

    public function down(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        if (! is_string($definition) || substr_count($definition, self::AFTER) !== 1 || substr_count($definition, self::CHECK) !== 1) {
            throw new RuntimeException('native_evidence_reference_guard_contract_mismatch');
        }
        DB::unprepared(str_replace([self::AFTER, self::CHECK], [self::BEFORE, ''], $definition));
    }
};
