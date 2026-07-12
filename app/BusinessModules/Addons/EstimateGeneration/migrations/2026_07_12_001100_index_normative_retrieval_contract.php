<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (! DB::table('estimate_normative_retrieval_rollouts')->where('schema_version', 'normative-retrieval-v1')->where('status', 'complete')->exists()) {
            throw new RuntimeException('Normative retrieval backfill is incomplete.');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_collection_unit_idx ON estimate_norms (collection_id, canonical_unit)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_section_dimension_idx ON estimate_norms (section_code, unit_dimension)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimate_norms_search_vector_gin ON estimate_norms USING gin (search_vector)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_search_vector_gin');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_section_dimension_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimate_norms_collection_unit_idx');
    }
};
