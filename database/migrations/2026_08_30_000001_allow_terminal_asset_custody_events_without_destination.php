<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE asset_custody_events DROP CONSTRAINT asset_custody_events_one_destination_check');
        DB::statement(<<<'SQL'
            ALTER TABLE asset_custody_events
            ADD CONSTRAINT asset_custody_events_one_destination_check
            CHECK (
                (
                    event_type IN ('retired', 'lost')
                    AND to_warehouse_id IS NULL
                    AND to_project_id IS NULL
                    AND to_user_id IS NULL
                )
                OR
                (
                    event_type NOT IN ('retired', 'lost')
                    AND (
                        (CASE WHEN to_warehouse_id IS NULL THEN 0 ELSE 1 END) +
                        (CASE WHEN to_project_id IS NULL THEN 0 ELSE 1 END) +
                        (CASE WHEN to_user_id IS NULL THEN 0 ELSE 1 END) = 1
                    )
                )
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE asset_custody_events DROP CONSTRAINT asset_custody_events_one_destination_check');
        DB::statement(<<<'SQL'
            ALTER TABLE asset_custody_events
            ADD CONSTRAINT asset_custody_events_one_destination_check
            CHECK (
                (CASE WHEN to_warehouse_id IS NULL THEN 0 ELSE 1 END) +
                (CASE WHEN to_project_id IS NULL THEN 0 ELSE 1 END) +
                (CASE WHEN to_user_id IS NULL THEN 0 ELSE 1 END) = 1
            ) NOT VALID
            SQL);
    }
};
