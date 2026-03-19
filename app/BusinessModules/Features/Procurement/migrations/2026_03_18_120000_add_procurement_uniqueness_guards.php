<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS purchase_requests_org_site_request_unique
            ON purchase_requests (organization_id, site_request_id)
            WHERE site_request_id IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS purchase_orders_purchase_request_unique
            ON purchase_orders (purchase_request_id)
            WHERE purchase_request_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS purchase_requests_org_site_request_unique');
        DB::statement('DROP INDEX IF EXISTS purchase_orders_purchase_request_unique');
    }
};
