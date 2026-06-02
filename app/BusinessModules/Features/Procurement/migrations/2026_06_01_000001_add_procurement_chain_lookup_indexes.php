<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX IF NOT EXISTS payment_documents_procurement_order_lookup_idx
            ON payment_documents ((metadata->>'purchase_order_id'))
            WHERE deleted_at IS NULL AND metadata IS NOT NULL AND jsonb_exists(metadata::jsonb, 'purchase_order_id')"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS payment_documents_procurement_order_lookup_idx');
    }
};
