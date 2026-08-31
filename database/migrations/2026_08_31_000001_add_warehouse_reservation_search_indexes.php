<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS asset_reservations_document_number_trgm_idx ON asset_reservations USING GIN (lower(COALESCE(metadata->>'document_number', '')) gin_trgm_ops)");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS asset_reservations_reason_trgm_idx ON asset_reservations USING GIN (lower(COALESCE(reason, '')) gin_trgm_ops)");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS materials_name_trgm_idx ON materials USING GIN (lower(name) gin_trgm_ops)');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS materials_code_trgm_idx ON materials USING GIN (lower(COALESCE(code, '')) gin_trgm_ops)");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS projects_name_trgm_idx ON projects USING GIN (lower(name) gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS asset_reservations_document_number_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS asset_reservations_reason_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS materials_name_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS materials_code_trgm_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS projects_name_trgm_idx');
    }
};
