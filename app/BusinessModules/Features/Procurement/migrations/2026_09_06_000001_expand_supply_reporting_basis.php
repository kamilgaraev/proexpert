<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_order_promise_versions', 'sent_purchase_order_line_owners', 'supply_lifecycle_events', 'supply_reliability_rows'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN unit_dimension TYPE varchar(128), ALTER COLUMN unit_code TYPE varchar(64), ALTER COLUMN conversion_version TYPE varchar(128)");
        }
        DB::statement('ALTER TABLE purchase_order_promise_versions ALTER COLUMN value_basis TYPE text, ALTER COLUMN tax_basis TYPE text, ALTER COLUMN freight_basis TYPE text');
        DB::statement('ALTER TABLE supply_reliability_rows ALTER COLUMN value_basis TYPE text');
        $this->replaceWarehouseGuard(
            'OR source_receipt.warehouse_id IS DISTINCT FROM source_promise.warehouse_id',
            'OR (source_promise.warehouse_id IS NOT NULL AND source_receipt.warehouse_id IS DISTINCT FROM source_promise.warehouse_id) OR NOT EXISTS (SELECT 1 FROM organization_warehouses receipt_warehouse WHERE receipt_warehouse.id = source_receipt.warehouse_id AND receipt_warehouse.organization_id = NEW.organization_id)',
        );
        $this->replaceWarehouseGuard(
            'OR return_movement.warehouse_id IS DISTINCT FROM source_promise.warehouse_id',
            'OR (source_promise.warehouse_id IS NOT NULL AND return_movement.warehouse_id IS DISTINCT FROM source_promise.warehouse_id)',
        );
        $this->replaceWarehouseGuard($this->originalReturnUnitGuard(), $this->receiptLotReturnUnitGuard());
        DB::statement('ALTER TABLE supply_lifecycle_events ALTER COLUMN promise_version_id DROP NOT NULL');
        foreach ($this->optionalPromiseGuards() as [$function, $before, $after]) {
            $this->replaceGuard($function, $before, $after);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supply_lifecycle_events ALTER COLUMN promise_version_id SET NOT NULL');
        foreach (array_reverse($this->optionalPromiseGuards()) as [$function, $before, $after]) {
            $this->replaceGuard($function, $after, $before);
        }
        $this->replaceWarehouseGuard($this->receiptLotReturnUnitGuard(), $this->originalReturnUnitGuard());
        $this->replaceWarehouseGuard(
            'OR (source_promise.warehouse_id IS NOT NULL AND return_movement.warehouse_id IS DISTINCT FROM source_promise.warehouse_id)',
            'OR return_movement.warehouse_id IS DISTINCT FROM source_promise.warehouse_id',
        );
        $this->replaceWarehouseGuard(
            'OR (source_promise.warehouse_id IS NOT NULL AND source_receipt.warehouse_id IS DISTINCT FROM source_promise.warehouse_id) OR NOT EXISTS (SELECT 1 FROM organization_warehouses receipt_warehouse WHERE receipt_warehouse.id = source_receipt.warehouse_id AND receipt_warehouse.organization_id = NEW.organization_id)',
            'OR source_receipt.warehouse_id IS DISTINCT FROM source_promise.warehouse_id',
        );
        foreach (['purchase_order_promise_versions', 'sent_purchase_order_line_owners', 'supply_lifecycle_events', 'supply_reliability_rows'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN unit_dimension TYPE varchar(32), ALTER COLUMN unit_code TYPE varchar(32), ALTER COLUMN conversion_version TYPE varchar(64)");
        }
        DB::statement('ALTER TABLE purchase_order_promise_versions ALTER COLUMN value_basis TYPE varchar(128), ALTER COLUMN tax_basis TYPE varchar(32), ALTER COLUMN freight_basis TYPE varchar(32)');
        DB::statement('ALTER TABLE supply_reliability_rows ALTER COLUMN value_basis TYPE varchar(128)');
    }

    private function replaceWarehouseGuard(string $before, string $after): void
    {
        $this->replaceGuard('most_supply_lifecycle_event_source_identity_v1', $before, $after);
    }

    private function replaceGuard(string $function, string $before, string $after): void
    {
        $row = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$function.'()']);
        $definition = (string) ($row->definition ?? '');
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Unexpected supply lifecycle warehouse guard definition.');
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    private function optionalPromiseGuards(): array
    {
        $promise = <<<'SQL'
source_promise.id IS NULL
       OR source_promise.organization_id <> NEW.organization_id
       OR source_promise.purchase_order_id <> NEW.purchase_order_id
       OR source_promise.purchase_order_item_id <> NEW.purchase_order_item_id
       OR source_promise.unit_dimension <> NEW.unit_dimension
       OR source_promise.unit_code <> NEW.unit_code
       OR source_promise.conversion_version <> NEW.conversion_version
SQL;
        $receiptSource = <<<'SQL'
(NEW.promise_version_id IS NULL AND (
    NEW.event_type NOT IN ('received', 'receipt_reversed', 'returned')
    OR NOT EXISTS (
        SELECT 1
        FROM purchase_receipt_lines operational_line
        JOIN purchase_receipts operational_receipt ON operational_receipt.id = operational_line.purchase_receipt_id
        JOIN purchase_order_items operational_item ON operational_item.id = operational_line.purchase_order_item_id
        JOIN purchase_orders operational_order ON operational_order.id = operational_item.purchase_order_id
        JOIN purchase_receipt_inventory_lots operational_lot ON operational_lot.purchase_receipt_line_id = operational_line.id
        JOIN warehouse_balances operational_balance ON operational_balance.id = operational_lot.warehouse_balance_id
        JOIN warehouse_movements operational_movement ON operational_movement.id = operational_lot.receipt_warehouse_movement_id
        WHERE operational_line.id = COALESCE(source_line.id, return_line.id)
          AND operational_receipt.organization_id = NEW.organization_id
          AND operational_order.organization_id = NEW.organization_id
          AND operational_receipt.purchase_order_id = NEW.purchase_order_id
          AND operational_item.purchase_order_id = NEW.purchase_order_id
          AND operational_item.id = NEW.purchase_order_item_id
          AND operational_lot.organization_id = NEW.organization_id
          AND operational_balance.organization_id = NEW.organization_id
          AND operational_balance.warehouse_id = operational_receipt.warehouse_id
          AND operational_balance.material_id = operational_item.material_id
          AND operational_movement.organization_id = NEW.organization_id
          AND operational_movement.warehouse_id = operational_receipt.warehouse_id
          AND operational_movement.material_id = operational_item.material_id
          AND operational_lot.unit_dimension = NEW.unit_dimension
          AND operational_lot.unit_code = NEW.unit_code
          AND operational_lot.conversion_version = NEW.conversion_version
          AND (NEW.event_type <> 'returned' OR (
              return_movement.project_id IS NOT DISTINCT FROM operational_movement.project_id
              AND return_movement.material_id = operational_movement.material_id
          ))
          AND (NEW.event_type <> 'receipt_reversed' OR (
              reversal_movement.project_id IS NOT DISTINCT FROM operational_movement.project_id
              AND reversal_movement.material_id = operational_movement.material_id
          ))
    )
))
SQL;
        $returnPromise = <<<'SQL'
source_promise.id IS NULL
       OR source_promise.organization_id <> NEW.organization_id
       OR source_promise.purchase_order_id <> source_receipt.purchase_order_id
       OR source_promise.purchase_order_item_id <> source_line.purchase_order_item_id
SQL;
        $function = 'most_supply_lifecycle_event_source_identity_v1';

        return [
            [$function, $promise, '(NEW.promise_version_id IS NOT NULL AND ('.$promise."))\n       OR ".$receiptSource],
            [$function,
                'OR return_movement.project_id IS DISTINCT FROM source_promise.project_id',
                'OR (NEW.promise_version_id IS NOT NULL AND return_movement.project_id IS DISTINCT FROM source_promise.project_id)',
            ],
            [$function,
                'OR return_movement.material_id IS DISTINCT FROM source_promise.material_id',
                'OR (NEW.promise_version_id IS NOT NULL AND return_movement.material_id IS DISTINCT FROM source_promise.material_id)',
            ],
            [$function,
                'OR reversed_event.promise_version_id <> NEW.promise_version_id',
                'OR reversed_event.promise_version_id IS DISTINCT FROM NEW.promise_version_id',
            ],
            ['most_purchase_receipt_return_identity_v1', $returnPromise,
                '(source_event.promise_version_id IS NOT NULL AND ('.$returnPromise.'))',
            ],
        ];
    }

    private function originalReturnUnitGuard(): string
    {
        return <<<'SQL'
OR (return_movement.metadata->>'unit_dimension')
                  IS DISTINCT FROM NEW.unit_dimension
               OR (return_movement.metadata->>'unit_code')
                  IS DISTINCT FROM NEW.unit_code
               OR (return_movement.metadata->>'unit_conversion_version')
                  IS DISTINCT FROM NEW.conversion_version
SQL;
    }

    private function receiptLotReturnUnitGuard(): string
    {
        return <<<'SQL'
OR NOT EXISTS (
                   SELECT 1
                   FROM purchase_receipt_inventory_lots return_lot
                   JOIN warehouse_movements receipt_movement
                     ON receipt_movement.id = return_lot.receipt_warehouse_movement_id
                   WHERE return_lot.purchase_receipt_line_id = return_line.id
                     AND return_lot.organization_id = NEW.organization_id
                     AND receipt_movement.organization_id = NEW.organization_id
                     AND receipt_movement.warehouse_id = return_receipt.warehouse_id
                     AND receipt_movement.material_id = return_movement.material_id
                     AND return_lot.unit_code = NEW.unit_code
                     AND return_movement.metadata->>'receipt_movement_id' = receipt_movement.id::text
                     AND return_movement.metadata->>'unit_dimension' = return_lot.unit_dimension
                     AND return_movement.metadata->>'unit_code' = return_lot.unit_code
                     AND return_movement.metadata->>'unit_conversion_version' = return_lot.conversion_version
               )
SQL;
    }
};
