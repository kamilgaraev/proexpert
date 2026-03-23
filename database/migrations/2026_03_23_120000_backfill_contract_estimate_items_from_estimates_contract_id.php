<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO contract_estimate_items (
                contract_id,
                estimate_id,
                estimate_item_id,
                quantity,
                amount,
                notes,
                created_at,
                updated_at
            )
            SELECT
                e.contract_id,
                e.id,
                ei.id,
                ei.quantity_total,
                COALESCE(ei.total_amount, ROUND(COALESCE(ei.quantity_total, 0) * COALESCE(ei.unit_price, 0), 2)),
                'legacy_backfill_from_estimates_contract_id',
                NOW(),
                NOW()
            FROM estimates e
            INNER JOIN estimate_items ei ON ei.estimate_id = e.id
            INNER JOIN contracts c ON c.id = e.contract_id
            WHERE
                e.contract_id IS NOT NULL
                AND ei.item_type = 'work'
                AND e.deleted_at IS NULL
                AND ei.deleted_at IS NULL
                AND c.deleted_at IS NULL
                AND c.organization_id = e.organization_id
                AND c.project_id = e.project_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM contract_estimate_items cei
                    WHERE cei.contract_id = e.contract_id
                        AND cei.estimate_item_id = ei.id
                )
        ");
    }

    public function down(): void
    {
        DB::table('contract_estimate_items')
            ->where('notes', 'legacy_backfill_from_estimates_contract_id')
            ->delete();
    }
};
