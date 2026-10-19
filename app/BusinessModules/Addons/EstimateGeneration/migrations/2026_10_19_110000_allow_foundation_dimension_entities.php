<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_TYPES = "NEW.payload->>'semantic_type' IN ('room','wall','opening','site','roof','roof_facet','roof_opening','quantity')";

    private const FOUNDATION_TYPES = "NEW.payload->>'semantic_type' IN ('room','wall','opening','site','roof','roof_facet','roof_opening','quantity','foundation_beam','foundation_slab')";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Foundation dimension entities require PostgreSQL.');
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_project_model_entity_payload_guard()'::regprocedure)");
        if (! is_string($definition) || substr_count($definition, self::PREVIOUS_TYPES) !== 1 || str_contains($definition, self::FOUNDATION_TYPES)) {
            throw new RuntimeException('foundation_dimension_entity_guard_contract_mismatch');
        }
        DB::unprepared(str_replace(self::PREVIOUS_TYPES, self::FOUNDATION_TYPES, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Foundation dimension entity history is forward-only.');
    }
};
