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

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX warehouse_movements_manual_idempotency_unique
            ON warehouse_movements (organization_id, movement_type, (metadata->>'idempotency_key'))
            WHERE metadata->>'idempotency_key' IS NOT NULL
              AND movement_type IN ('receipt', 'write_off', 'transfer_in', 'transfer_out', 'reservation', 'unreservation')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX asset_reservations_manual_idempotency_unique
            ON asset_reservations (organization_id, (metadata->>'idempotency_key'))
            WHERE metadata->>'idempotency_key' IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS asset_reservations_manual_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS warehouse_movements_manual_idempotency_unique');
    }
};
