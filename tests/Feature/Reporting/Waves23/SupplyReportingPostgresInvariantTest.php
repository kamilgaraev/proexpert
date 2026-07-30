<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class SupplyReportingPostgresInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Supply reporting invariants require isolated PostgreSQL.',
        );
    }

    public function test_transfer_pair_constraint_owns_material_and_warehouse_relationship(): void
    {
        $definition = (string) DB::scalar(
            "SELECT pg_get_functiondef('most_inventory_transfer_pair_v1'::regproc)",
        );

        self::assertStringContainsString('material_count <> 1', $definition);
        self::assertStringContainsString('warehouse_count <> 2', $definition);
        self::assertStringContainsString('relationship_count <> 1', $definition);
        self::assertStringContainsString('movement_out.to_warehouse_id = event_in.warehouse_id', $definition);
        self::assertStringContainsString('movement_in.from_warehouse_id = event_out.warehouse_id', $definition);
    }

    public function test_receipt_reversal_identity_and_lot_linkage_are_persisted(): void
    {
        self::assertTrue(Schema::hasColumns('purchase_receipt_lines', [
            'reversed_at',
            'reversal_warehouse_movement_id',
            'reversal_idempotency_key',
        ]));
        self::assertTrue(Schema::hasColumns('purchase_receipt_inventory_lots', [
            'purchase_receipt_line_id',
            'warehouse_balance_id',
            'receipt_warehouse_movement_id',
            'original_quantity',
            'reversed_quantity',
            'unit_dimension',
            'unit_code',
            'conversion_version',
        ]));

        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', [
                'receipt_line_reversal_idempotency_unique',
                'receipt_inventory_lot_line_unique',
                'receipt_inventory_lot_movement_unique',
            ])
            ->pluck('indexname')
            ->all();
        self::assertEqualsCanonicalizing([
            'receipt_line_reversal_idempotency_unique',
            'receipt_inventory_lot_line_unique',
            'receipt_inventory_lot_movement_unique',
        ], $indexes);
    }

    public function test_receipt_inventory_lot_identity_has_database_immutability_fence(): void
    {
        $definition = (string) DB::scalar(
            "SELECT pg_get_functiondef('most_receipt_inventory_lot_identity_v1'::regproc)",
        );

        foreach ([
            'purchase_receipt_line_id',
            'warehouse_balance_id',
            'receipt_warehouse_movement_id',
            'original_quantity',
            'unit_dimension',
            'unit_code',
            'conversion_version',
        ] as $column) {
            self::assertStringContainsString($column, $definition);
        }
    }
}
