<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptInventoryService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
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

    public function test_transfer_pair_rejects_movement_material_mismatch(): void
    {
        $organization = Organization::factory()->create();
        $now = now('UTC');
        $warehouseIds = [];
        foreach (['FROM', 'TO'] as $code) {
            $warehouseIds[] = DB::table('organization_warehouses')->insertGetId([
                'organization_id' => $organization->id,
                'name' => $code,
                'code' => 'SUPPLY-'.$code.'-'.$organization->id,
                'warehouse_type' => 'central',
                'is_main' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $materialIds = [];
        foreach (['A', 'B'] as $name) {
            $materialIds[] = DB::table('materials')->insertGetId([
                'organization_id' => $organization->id,
                'name' => 'Material '.$name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $out = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseIds[0],
            'material_id' => $materialIds[0],
            'movement_type' => 'transfer_out',
            'quantity' => '10.000',
            'to_warehouse_id' => $warehouseIds[1],
            'metadata' => json_encode([
                'reporting_source_version' => 1,
                'reporting_inventory_project_id' => null,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'kg:v1',
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $in = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseIds[1],
            'material_id' => $materialIds[1],
            'movement_type' => 'transfer_in',
            'quantity' => '10.000',
            'from_warehouse_id' => $warehouseIds[0],
            'metadata' => json_encode([
                'reporting_source_version' => 1,
                'reporting_inventory_project_id' => null,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'kg:v1',
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [$warehouseIds[0], $materialIds[0], $out, 'transfer_out', '-10.000000'],
            [$warehouseIds[1], $materialIds[1], $in, 'transfer_in', '10.000000'],
        ] as [$warehouseId, $materialId, $movementId, $type, $delta]) {
            DB::table('warehouse_inventory_events')->insert([
                'organization_id' => $organization->id,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'source_movement_id' => $movementId,
                'source_version' => 1,
                'event_type' => $type,
                'on_hand_delta' => $delta,
                'reserved_delta' => '0',
                'transfer_pair_key' => 'material-mismatch-'.$organization->id,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'conversion_version' => 'kg:v1',
                'occurred_at' => $now,
                'source_hash' => str_repeat('a', 64),
                'source_refs' => '[]',
            ]);
        }

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement('SET CONSTRAINTS warehouse_inventory_transfer_pair IMMEDIATE');
    }

    public function test_non_transfer_event_rejects_source_quantity_mismatch(): void
    {
        $organization = Organization::factory()->create();
        $now = now('UTC');
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Receipt source',
            'code' => 'SOURCE-'.$organization->id,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Receipt source material',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $movementId = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'receipt',
            'quantity' => '10.000000',
            'metadata' => json_encode([
                'reporting_source_version' => 1,
                'reporting_inventory_project_id' => null,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'kg:v1',
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('warehouse_inventory_events')->insert([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'source_movement_id' => $movementId,
            'source_version' => 1,
            'event_type' => 'receipt',
            'on_hand_delta' => '9.000000',
            'reserved_delta' => '0',
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'conversion_version' => 'kg:v1',
            'occurred_at' => $now,
            'source_hash' => str_repeat('b', 64),
            'source_refs' => '[]',
        ]);
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

    public function test_receipt_lot_allows_only_exact_irreversible_transition(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE supply_lot_transition_fixture (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    purchase_receipt_line_id bigint NOT NULL,
    warehouse_balance_id bigint NOT NULL,
    receipt_warehouse_movement_id bigint NOT NULL,
    original_quantity numeric(24, 6) NOT NULL,
    reversed_quantity numeric(24, 6) NOT NULL,
    unit_dimension varchar(128) NOT NULL,
    unit_code varchar(64) NOT NULL,
    conversion_version varchar(128) NOT NULL
)
SQL);
        DB::statement(
            'CREATE TRIGGER supply_lot_transition_fixture_guard '
            .'BEFORE UPDATE ON supply_lot_transition_fixture '
            .'FOR EACH ROW EXECUTE FUNCTION most_receipt_inventory_lot_identity_v1()',
        );
        DB::table('supply_lot_transition_fixture')->insert([
            'id' => 1,
            'organization_id' => 1,
            'purchase_receipt_line_id' => 1,
            'warehouse_balance_id' => 1,
            'receipt_warehouse_movement_id' => 1,
            'original_quantity' => '10.000000',
            'reversed_quantity' => '0.000000',
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'conversion_version' => 'kg:v1',
        ]);

        try {
            DB::table('supply_lot_transition_fixture')->where('id', 1)->update([
                'reversed_quantity' => '5.000000',
            ]);
            self::fail('Partial receipt reversal must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame('0.000000', (string) DB::table('supply_lot_transition_fixture')->value('reversed_quantity'));
        }

        DB::table('supply_lot_transition_fixture')->where('id', 1)->update([
            'reversed_quantity' => '10.000000',
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('supply_lot_transition_fixture')->where('id', 1)->update([
            'reversed_quantity' => '0.000000',
        ]);
    }

    public function test_receipt_reversal_same_key_is_idempotent_and_different_key_conflicts_under_process_race(): void
    {
        $same = $this->raceReceiptLineUpdates('same-key-0000001', 'same-key-0000001');
        self::assertSame(['applied', 'applied'], $same);

        $different = $this->raceReceiptLineUpdates('different-key-001', 'different-key-002');
        sort($different);
        self::assertSame(['applied', 'conflict'], $different);
    }

    public function test_two_processes_cannot_overship_one_project_delivery(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $now = now('UTC');
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Race material',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Race source',
            'code' => 'RACE-'.$organization->id,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('warehouse_balances')->insert([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'unit_price' => '100.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $allocation = WarehouseProjectAllocation::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'project_id' => $project->id,
            'allocated_quantity' => '10.000',
            'allocated_by_user_id' => $actor->id,
            'allocated_at' => $now,
        ]);
        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'material_id' => $materialId,
            'warehouse_id' => $warehouseId,
            'warehouse_project_allocation_id' => $allocation->id,
            'source_type' => 'warehouse',
            'status' => 'reserved',
            'requested_quantity' => '10.000',
            'reserved_quantity' => '10.000',
            'shipped_quantity' => '0.000',
            'accepted_quantity' => '0.000',
        ]);
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-supply-overship-'.bin2hex(random_bytes(8)),
        );
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $children[] = $harness->spawn($index, static function () use ($delivery, $actor): array {
                    try {
                        app(ProjectMaterialDeliveryService::class)->ship(
                            ProjectMaterialDelivery::query()->findOrFail($delivery->id),
                            User::query()->findOrFail($actor->id),
                            ['quantity' => 6],
                        );

                        return ['state' => 'applied'];
                    } catch (\DomainException) {
                        return ['state' => 'conflict'];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForChildren($children);
            $states = [
                (string) $harness->result(0)['state'],
                (string) $harness->result(1)['state'],
            ];
            sort($states);
            self::assertSame(['applied', 'conflict'], $states);
            self::assertSame(
                '6.000',
                (string) ProjectMaterialDelivery::query()->findOrFail($delivery->id)->shipped_quantity,
            );
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public function test_consumed_receipt_lot_cannot_be_reversed_from_another_inventory_batch(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $now = now('UTC');
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Receipt material',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Receipt warehouse',
            'code' => 'RECEIPT-'.$organization->id,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Receipt supplier',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'order_number' => 'RECEIPT-'.$organization->id,
            'order_date' => $now->toDateString(),
            'status' => 'received',
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $itemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId,
            'material_id' => $materialId,
            'material_name' => 'Receipt material',
            'quantity' => '10.000',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total_price' => '1000.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'organization_id' => $organization->id,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $warehouseId,
            'received_by_user_id' => $actor->id,
            'receipt_number' => 'RECEIPT-'.$organization->id,
            'receipt_date' => $now->toDateString(),
            'status' => 'posted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lineId = DB::table('purchase_receipt_lines')->insertGetId([
            'purchase_receipt_id' => $receiptId,
            'purchase_order_item_id' => $itemId,
            'quantity_received' => '10.000',
            'price' => '100.00',
            'total_amount' => '1000.00',
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $balanceId = DB::table('warehouse_balances')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => '5.000',
            'reserved_quantity' => '0.000',
            'unit_price' => '100.00',
            'batch_number' => 'purchase-receipt-line:'.$lineId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $movementId = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'receipt',
            'quantity' => '10.000',
            'price' => '100.00',
            'metadata' => json_encode([
                'reporting_source_version' => 1,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'kg:v1',
                'reporting_inventory_project_id' => null,
                'currency' => 'RUB',
                'currency_source' => 'warehouse_movement.price',
                'purchase_order_item_id' => $itemId,
                'batch_number' => 'purchase-receipt-line:'.$lineId,
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foreignOrderId = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'order_number' => 'FOREIGN-RECEIPT-'.$organization->id,
            'order_date' => $now->toDateString(),
            'status' => 'received',
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foreignItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $foreignOrderId,
            'material_id' => $materialId,
            'material_name' => 'Foreign receipt material',
            'quantity' => '10.000',
            'unit' => 'kg',
            'unit_price' => '100.00',
            'total_price' => '1000.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foreignLineId = DB::table('purchase_receipt_lines')->insertGetId([
            'purchase_receipt_id' => $receiptId,
            'purchase_order_item_id' => $foreignItemId,
            'quantity_received' => '10.000',
            'price' => '100.00',
            'total_amount' => '1000.00',
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foreignBalanceId = DB::table('warehouse_balances')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'unit_price' => '100.00',
            'batch_number' => 'purchase-receipt-line:'.$foreignLineId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foreignMovementId = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'receipt',
            'quantity' => '10.000',
            'price' => '100.00',
            'metadata' => json_encode([
                'reporting_source_version' => 1,
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'unit_conversion_version' => 'kg:v1',
                'reporting_inventory_project_id' => null,
                'currency' => 'RUB',
                'currency_source' => 'warehouse_movement.price',
                'purchase_order_item_id' => $foreignItemId,
                'batch_number' => 'purchase-receipt-line:'.$foreignLineId,
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::beginTransaction();
        try {
            DB::table('purchase_receipt_inventory_lots')->insert([
                'organization_id' => $organization->id,
                'purchase_receipt_line_id' => $foreignLineId,
                'warehouse_balance_id' => $foreignBalanceId,
                'receipt_warehouse_movement_id' => $foreignMovementId,
                'original_quantity' => '10.000000',
                'reversed_quantity' => '0.000000',
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'conversion_version' => 'kg:v1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Receipt line from another purchase order must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            DB::rollBack();
            self::assertFalse(
                DB::table('purchase_receipt_inventory_lots')
                    ->where('purchase_receipt_line_id', $foreignLineId)
                    ->exists(),
            );
        }
        DB::beginTransaction();
        try {
            DB::table('purchase_receipt_inventory_lots')->insert([
                'organization_id' => $organization->id,
                'purchase_receipt_line_id' => $lineId,
                'warehouse_balance_id' => $balanceId,
                'receipt_warehouse_movement_id' => $movementId,
                'original_quantity' => '9.000000',
                'reversed_quantity' => '0.000000',
                'unit_dimension' => 'mass',
                'unit_code' => 'kg',
                'conversion_version' => 'kg:v1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Receipt lot source quantity mismatch must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            DB::rollBack();
            self::assertFalse(
                DB::table('purchase_receipt_inventory_lots')
                    ->where('purchase_receipt_line_id', $lineId)
                    ->exists(),
            );
        }

        DB::table('purchase_receipt_inventory_lots')->insert([
            'organization_id' => $organization->id,
            'purchase_receipt_line_id' => $lineId,
            'warehouse_balance_id' => $balanceId,
            'receipt_warehouse_movement_id' => $movementId,
            'original_quantity' => '10.000000',
            'reversed_quantity' => '0.000000',
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'conversion_version' => 'kg:v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['warehouse_balances', $balanceId, ['batch_number' => 'tampered-batch']],
            ['purchase_receipts', $receiptId, ['purchase_order_id' => $foreignOrderId]],
            ['purchase_order_items', $itemId, ['purchase_order_id' => $foreignOrderId]],
        ] as [$table, $id, $attributes]) {
            DB::beginTransaction();
            try {
                DB::table($table)->where('id', $id)->update($attributes);
                self::fail($table.' linked identity mutation must be rejected.');
            } catch (\Illuminate\Database\QueryException) {
                DB::rollBack();
                self::assertTrue(DB::table($table)->where('id', $id)->exists());
            }
        }
        DB::table('purchase_receipt_inventory_lots')
            ->where('purchase_receipt_line_id', $lineId)
            ->update(['reversed_quantity' => '10.000000']);
        $unrelatedMovementId = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'write_off',
            'quantity' => '10.000',
            'price' => '100.00',
            'operation_category' => 'procurement_receipt_reversal',
            'metadata' => json_encode([
                'reversed_purchase_receipt_line_id' => $foreignLineId,
                'reversed_receipt_movement_id' => $foreignMovementId,
            ], JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::beginTransaction();
        try {
            DB::table('purchase_receipt_lines')->where('id', $lineId)->update([
                'reversed_at' => $now,
                'reversed_by_user_id' => $actor->id,
                'reversal_reason_code' => 'supplier_return',
                'reversal_warehouse_movement_id' => $unrelatedMovementId,
                'reversal_idempotency_key' => 'forged-reversal-'.$lineId,
            ]);
            self::fail('Receipt reversal must reject an unrelated inventory movement.');
        } catch (\Illuminate\Database\QueryException) {
            DB::rollBack();
            self::assertNull(DB::table('purchase_receipt_lines')->where('id', $lineId)->value('reversed_at'));
        }
        $line = PurchaseReceiptLine::query()
            ->with(['purchaseReceipt', 'purchaseOrderItem'])
            ->findOrFail($lineId);

        $this->expectException(\DomainException::class);
        app(PurchaseReceiptInventoryService::class)->reverse(
            $line,
            'supplier_return',
            (int) $actor->id,
            CarbonImmutable::now('UTC'),
        );
    }

    public function test_linked_inventory_event_rejects_source_valuation_metadata_mutation(): void
    {
        $organization = Organization::factory()->create();
        $now = now('UTC');
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Immutable source',
            'code' => 'IMMUTABLE-'.$organization->id,
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Immutable material',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $metadata = [
            'reporting_source_version' => 1,
            'reporting_inventory_project_id' => null,
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'unit_conversion_version' => 'kg:v1',
            'currency' => 'RUB',
            'currency_source' => 'warehouse_movement.price',
        ];
        $movementId = DB::table('warehouse_movements')->insertGetId([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => 'receipt',
            'quantity' => '10.000000',
            'price' => '100.00',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'movement_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('warehouse_inventory_events')->insert([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'source_movement_id' => $movementId,
            'source_version' => 1,
            'event_type' => 'receipt',
            'on_hand_delta' => '10.000000',
            'reserved_delta' => '0',
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'conversion_version' => 'kg:v1',
            'unit_price_minor' => 10000,
            'currency' => 'RUB',
            'currency_source' => 'warehouse_movement.price',
            'occurred_at' => $now,
            'source_hash' => str_repeat('c', 64),
            'source_refs' => '[]',
        ]);

        $metadata['currency'] = 'USD';
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('warehouse_movements')->where('id', $movementId)->update([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_supply_lifecycle_event_rejects_item_outside_promise_identity(): void
    {
        $organization = Organization::factory()->create();
        $now = now('UTC');
        $supplierId = DB::table('suppliers')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Identity supplier',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'order_number' => 'IDENTITY-'.$organization->id,
            'order_date' => $now->toDateString(),
            'status' => 'sent',
            'total_amount' => '200.00',
            'currency' => 'RUB',
            'pricing_source' => 'test',
            'metadata' => json_encode([
                'tax_basis' => 'included',
                'freight_basis' => 'excluded',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $materialIds = [];
        foreach (['A', 'B'] as $name) {
            $materialIds[] = DB::table('materials')->insertGetId([
                'organization_id' => $organization->id,
                'name' => 'Identity material '.$name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $itemIds = [];
        foreach ($materialIds as $index => $materialId) {
            $itemIds[] = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $orderId,
                'material_id' => $materialId,
                'material_name' => 'Identity item '.$index,
                'quantity' => '1.000',
                'unit' => 'kg',
                'unit_price' => '100.00',
                'total_price' => '100.00',
                'metadata' => json_encode([
                    'unit_dimension' => 'mass',
                    'unit_conversion_version' => 'kg:v1',
                    'reporting_source_version' => 1,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $promiseAttributes = [
            'organization_id' => $organization->id,
            'purchase_order_id' => $orderId,
            'purchase_order_item_id' => $itemIds[0],
            'promise_version' => 1,
            'supplier_id' => $supplierId,
            'material_id' => $materialIds[0],
            'ordered_quantity' => '1.000000',
            'ordered_value_minor' => 10000,
            'value_basis' => 'RUB:test:included:excluded',
            'unit_dimension' => 'mass',
            'unit_code' => 'kg',
            'conversion_version' => 'kg:v1',
            'promised_at' => $now,
            'promise_timezone' => 'UTC',
            'currency' => 'RUB',
            'currency_source' => 'test',
            'tax_basis' => 'included',
            'freight_basis' => 'excluded',
            'source_version' => 1,
            'effective_from' => $now,
            'source_hash' => str_repeat('d', 64),
        ];
        DB::beginTransaction();
        try {
            DB::table('purchase_order_promise_versions')->insert(
                array_merge($promiseAttributes, ['ordered_quantity' => '2.000000']),
            );
            self::fail('Promise quantity must match the immutable purchase order item.');
        } catch (\Illuminate\Database\QueryException) {
            DB::rollBack();
            self::assertFalse(
                DB::table('purchase_order_promise_versions')
                    ->where('purchase_order_item_id', $itemIds[0])
                    ->exists(),
            );
        }
        $promiseId = DB::table('purchase_order_promise_versions')->insertGetId($promiseAttributes);

        foreach ([
            ['purchase_order_item_id' => $itemIds[1], 'source_id' => $orderId, 'key' => 'mismatched-item'],
            ['purchase_order_item_id' => $itemIds[0], 'source_id' => $orderId + 999999, 'key' => 'missing-source'],
        ] as $case) {
            DB::beginTransaction();
            try {
                DB::table('supply_lifecycle_events')->insert([
                    'organization_id' => $organization->id,
                    'purchase_order_id' => $orderId,
                    'purchase_order_item_id' => $case['purchase_order_item_id'],
                    'promise_version_id' => $promiseId,
                    'event_type' => 'sent',
                    'source_type' => 'purchase_order',
                    'source_id' => $case['source_id'],
                    'source_version' => 1,
                    'signed_quantity' => '0',
                    'unit_dimension' => 'mass',
                    'unit_code' => 'kg',
                    'conversion_version' => 'kg:v1',
                    'occurred_at' => $now,
                    'idempotency_key' => $case['key'].'-'.$organization->id,
                    'source_hash' => str_repeat('e', 64),
                ]);
                self::fail($case['key'].' lifecycle identity must be rejected.');
            } catch (\Illuminate\Database\QueryException) {
                DB::rollBack();
            }
        }
        self::assertSame(
            0,
            DB::table('supply_lifecycle_events')->where('organization_id', $organization->id)->count(),
        );
    }

    public function test_receipt_and_inventory_source_functions_expose_complete_identity_fences(): void
    {
        $receipt = (string) DB::scalar(
            "SELECT pg_get_functiondef('most_receipt_inventory_lot_source_identity_v1'::regproc)",
        );
        $movement = (string) DB::scalar(
            "SELECT pg_get_functiondef('most_warehouse_reporting_movement_identity_v1'::regproc)",
        );
        $lifecycle = (string) DB::scalar(
            "SELECT pg_get_functiondef('most_supply_lifecycle_event_source_identity_v1'::regproc)",
        );

        self::assertStringContainsString(
            'source_item.purchase_order_id <> source_receipt.purchase_order_id',
            $receipt,
        );
        self::assertStringContainsString('unit_conversion_version', $receipt);
        self::assertStringContainsString('new.metadata IS DISTINCT FROM old.metadata', $movement);
        self::assertStringContainsString('new.price IS DISTINCT FROM old.price', $movement);
        self::assertStringContainsString(
            'source_promise.purchase_order_item_id <> new.purchase_order_item_id',
            $lifecycle,
        );
        self::assertStringContainsString(
            'reversed_event.promise_version_id <> new.promise_version_id',
            $lifecycle,
        );
    }

    private function createReceiptLineTransitionFixture(
        \Illuminate\Database\ConnectionInterface $connection,
        string $table,
    ): void {
        $connection->statement(<<<SQL
CREATE TABLE {$table} (
    id bigint PRIMARY KEY,
    purchase_receipt_id bigint NOT NULL,
    purchase_order_item_id bigint NOT NULL,
    quantity_received numeric(15, 3) NOT NULL,
    price numeric(15, 2) NOT NULL,
    total_amount numeric(15, 2) NOT NULL,
    reversed_at timestamptz NULL,
    reversed_by_user_id bigint NULL,
    reversal_reason_code varchar(64) NULL,
    reversal_warehouse_movement_id bigint NULL,
    reversal_idempotency_key varchar(128) NULL
)
SQL);
        $connection->statement(
            "CREATE TRIGGER {$table}_guard "
            ."BEFORE UPDATE ON {$table} "
            .'FOR EACH ROW EXECUTE FUNCTION most_purchase_receipt_line_reversal_v1()',
        );
        $connection->table($table)->insert([
            'id' => 1,
            'purchase_receipt_id' => 1,
            'purchase_order_item_id' => 1,
            'quantity_received' => '10.000',
            'price' => '100.00',
            'total_amount' => '1000.00',
        ]);
    }

    private function raceReceiptLineUpdates(string $firstKey, string $secondKey): array
    {
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-supply-reversal-'.bin2hex(random_bytes(8)),
        );
        $table = 'supply_receipt_line_transition_'.bin2hex(random_bytes(6));
        $setup = $harness->independentConnection($table.'_setup');
        $this->createReceiptLineTransitionFixture($setup, $table);
        $children = [];
        try {
            foreach ([$firstKey, $secondKey] as $index => $key) {
                $children[] = $harness->spawn($index, static function () use ($key, $table): array {
                    try {
                        DB::table($table)->where('id', 1)->update([
                            'reversed_at' => '2026-07-30 10:00:00+00',
                            'reversed_by_user_id' => 7,
                            'reversal_reason_code' => 'supplier_return',
                            'reversal_warehouse_movement_id' => 99,
                            'reversal_idempotency_key' => $key,
                        ]);

                        return ['state' => 'applied'];
                    } catch (\Illuminate\Database\QueryException) {
                        return ['state' => 'conflict'];
                    }
                });
            }
            $harness->release(0);
            $harness->release(1);
            $harness->waitForChildren($children);

            return [
                (string) $harness->result(0)['state'],
                (string) $harness->result(1)['state'],
            ];
        } finally {
            $harness->terminateAndReap($children);
            $setup->statement("DROP TABLE IF EXISTS {$table}");
            $harness->cleanup();
        }
    }
}
