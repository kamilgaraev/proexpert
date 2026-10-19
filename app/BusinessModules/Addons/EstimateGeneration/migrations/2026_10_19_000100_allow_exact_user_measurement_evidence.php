<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = <<<'SQL'
ELSIF NEW.type = 'measured' THEN IF NEW.value - ARRAY['quantity','unit','method'] <> '{}'::jsonb OR NOT NEW.value ? 'quantity' OR NOT NEW.value ? 'unit' OR jsonb_typeof(NEW.value->'quantity') <> 'number'
SQL;

    private const AFTER = <<<'SQL'
ELSIF NEW.type = 'measured' THEN IF NEW.value - ARRAY['quantity','unit','method'] <> '{}'::jsonb OR NOT NEW.value ? 'quantity' OR NOT NEW.value ? 'unit' OR NOT (jsonb_typeof(NEW.value->'quantity') = 'number' OR (NEW.source_type = 'user_input' AND NEW.producer_name = 'user_input_normalizer' AND jsonb_typeof(NEW.value->'quantity') = 'string' AND NEW.value->>'quantity' ~ '^(0|[1-9][0-9]*)(\.[0-9]{1,4})?$'))
SQL;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Exact user measurement evidence requires PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_evidence_semantic_guard()'::regprocedure)");
        if (is_string($definition) && substr_count($definition, self::AFTER) === 1) {
            return;
        }
        if (! is_string($definition) || substr_count($definition, self::BEFORE) !== 1) {
            throw new RuntimeException('exact_user_measurement_evidence_guard_contract_mismatch');
        }
        DB::unprepared(str_replace(self::BEFORE, self::AFTER, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Exact measurement provenance history is forward-only.');
    }
};
