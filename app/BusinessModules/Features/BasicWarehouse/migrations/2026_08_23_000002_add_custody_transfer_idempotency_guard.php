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
CREATE UNIQUE INDEX warehouse_movements_custody_idempotency_unique
ON warehouse_movements (organization_id, (metadata->>'idempotency_key'))
WHERE movement_type = 'transfer_out'
  AND operation_category IN ('responsible_issue', 'responsible_return')
  AND metadata->>'idempotency_key' IS NOT NULL
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS warehouse_movements_custody_idempotency_unique');
        }
    }
};
