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
        $invalidIndex = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) '.
            "AND indrelid = 'estimates'::regclass AND indisvalid = false",
            ['estimates_active_org_name_id_idx'],
        );
        if ($invalidIndex !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimates_active_org_name_id_idx');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS estimates_active_org_name_id_idx ON estimates (organization_id, name, id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS estimates_active_org_name_id_idx');
    }
};
