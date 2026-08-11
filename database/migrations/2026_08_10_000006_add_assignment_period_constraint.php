<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'machinery_assignments_no_active_overlap';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE machinery_assignments
            ADD CONSTRAINT machinery_assignments_no_active_overlap
            EXCLUDE USING gist (
                organization_asset_id WITH =,
                tsrange(planned_start_at, COALESCE(planned_end_at, 'infinity'::timestamp), '[)') WITH &&
            )
            WHERE (status = 'active' AND deleted_at IS NULL AND organization_asset_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE machinery_assignments DROP CONSTRAINT IF EXISTS '.self::NAME);
        }
    }
};
