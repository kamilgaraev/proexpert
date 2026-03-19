<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $this->createIndexIfDataIsClean(
            duplicateCheckSql: '
                SELECT organization_id, site_request_id
                FROM purchase_requests
                WHERE site_request_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY organization_id, site_request_id
                HAVING COUNT(*) > 1
                LIMIT 1
            ',
            uniqueIndexSql: '
                CREATE UNIQUE INDEX IF NOT EXISTS purchase_requests_org_site_request_unique
                ON purchase_requests (organization_id, site_request_id)
                WHERE site_request_id IS NOT NULL AND deleted_at IS NULL
            ',
            fallbackIndexSql: '
                CREATE INDEX IF NOT EXISTS purchase_requests_org_site_request_lookup_idx
                ON purchase_requests (organization_id, site_request_id)
                WHERE site_request_id IS NOT NULL AND deleted_at IS NULL
            ',
            logContext: ['table' => 'purchase_requests', 'index' => 'purchase_requests_org_site_request_unique']
        );

        $this->createIndexIfDataIsClean(
            duplicateCheckSql: '
                SELECT purchase_request_id
                FROM purchase_orders
                WHERE purchase_request_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY purchase_request_id
                HAVING COUNT(*) > 1
                LIMIT 1
            ',
            uniqueIndexSql: '
                CREATE UNIQUE INDEX IF NOT EXISTS purchase_orders_purchase_request_unique
                ON purchase_orders (purchase_request_id)
                WHERE purchase_request_id IS NOT NULL AND deleted_at IS NULL
            ',
            fallbackIndexSql: '
                CREATE INDEX IF NOT EXISTS purchase_orders_purchase_request_lookup_idx
                ON purchase_orders (purchase_request_id)
                WHERE purchase_request_id IS NOT NULL AND deleted_at IS NULL
            ',
            logContext: ['table' => 'purchase_orders', 'index' => 'purchase_orders_purchase_request_unique']
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS purchase_requests_org_site_request_unique');
        DB::statement('DROP INDEX IF EXISTS purchase_requests_org_site_request_lookup_idx');
        DB::statement('DROP INDEX IF EXISTS purchase_orders_purchase_request_unique');
        DB::statement('DROP INDEX IF EXISTS purchase_orders_purchase_request_lookup_idx');
    }

    private function createIndexIfDataIsClean(
        string $duplicateCheckSql,
        string $uniqueIndexSql,
        string $fallbackIndexSql,
        array $logContext
    ): void {
        $duplicate = DB::selectOne($duplicateCheckSql);

        if ($duplicate === null) {
            DB::statement($uniqueIndexSql);
            return;
        }

        DB::statement($fallbackIndexSql);

        Log::warning('procurement.uniqueness_guard_skipped_due_to_duplicates', [
            'duplicate_sample' => (array) $duplicate,
            ...$logContext,
        ]);
    }
};
