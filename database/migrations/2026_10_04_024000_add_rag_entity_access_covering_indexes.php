<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'estimate_norms_access_identity_idx' => 'estimate_norms (collection_id, section_id, id)',
        'design_ifc_model_elements_access_identity_idx' => 'design_ifc_model_elements (organization_id, project_id, version_id, derivative_id, id) INCLUDE (express_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $invalid = DB::selectOne('SELECT 1 FROM pg_index AS index_state '
                .'JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '
                .'JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '
                .'WHERE index_namespace.nspname = current_schema() AND index_class.relname = ? AND NOT index_state.indisvalid', [$name]);
            if ($invalid !== null) { DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name); }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
        DB::statement('ANALYZE estimate_norms');
        DB::statement('ANALYZE design_ifc_model_elements');
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
