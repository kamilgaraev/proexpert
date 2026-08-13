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

        DB::statement('ALTER TABLE estimate_generation_sheet_analysis_operations DROP CONSTRAINT IF EXISTS eg_sheet_analysis_scope_kind_uq');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sheet_analysis_scope_kind_idx');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_sheet_analysis_scope_kind_idx ON estimate_generation_sheet_analysis_operations (session_id, document_id, unit_id, source_version, kind)');
    }

    public function down(): void
    {
        throw new LogicException('estimate_generation_sheet_analysis_retry_lineages_are_irreversible');
    }
};
