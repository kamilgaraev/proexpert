<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX commercial_payments_one_open_renewal_attempt_unique
ON commercial_payments (commercial_order_id)
WHERE role = 'renewal'
  AND provider_status IN ('created', 'pending', 'waiting_for_capture', 'unknown', 'succeeded')
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commercial_payments_one_open_renewal_attempt_unique');
    }
};
