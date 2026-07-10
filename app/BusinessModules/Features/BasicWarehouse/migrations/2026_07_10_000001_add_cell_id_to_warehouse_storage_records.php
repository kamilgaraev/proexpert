<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_storage_cells', function (Blueprint $table): void {
            $table->jsonb('storage_conditions')->nullable();
        });

        Schema::table('warehouse_balances', function (Blueprint $table): void {
            $table->foreignId('cell_id')
                ->nullable()
                ->constrained('warehouse_storage_cells')
                ->restrictOnDelete();
            $table->index(['warehouse_id', 'cell_id'], 'warehouse_balances_warehouse_cell_idx');
        });

        Schema::table('inventory_act_items', function (Blueprint $table): void {
            $table->foreignId('cell_id')
                ->nullable()
                ->constrained('warehouse_storage_cells')
                ->restrictOnDelete();
            $table->index(['inventory_act_id', 'cell_id'], 'inventory_act_items_act_cell_idx');
        });

        Schema::table('warehouse_movements', function (Blueprint $table): void {
            $table->foreignId('cell_id')
                ->nullable()
                ->constrained('warehouse_storage_cells')
                ->restrictOnDelete();
            $table->index(['warehouse_id', 'cell_id', 'movement_date'], 'warehouse_movements_wh_cell_date_idx');
        });

        DB::statement(<<<'SQL'
            INSERT INTO warehouse_storage_cells (
                organization_id,
                warehouse_id,
                zone_id,
                name,
                code,
                cell_type,
                status,
                rack_number,
                shelf_number,
                bin_number,
                capacity,
                max_weight,
                storage_conditions,
                is_active,
                notes,
                created_at,
                updated_at
            )
            SELECT
                warehouse.organization_id,
                zone.warehouse_id,
                zone.id,
                zone.name,
                zone.code,
                zone.zone_type,
                CASE WHEN zone.is_active THEN 'available' ELSE 'archived' END,
                zone.rack_number,
                zone.shelf_number,
                zone.cell_number,
                zone.capacity,
                zone.max_weight,
                CAST(zone.storage_conditions AS jsonb),
                zone.is_active,
                zone.notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM warehouse_zones AS zone
            INNER JOIN organization_warehouses AS warehouse
                ON warehouse.id = zone.warehouse_id
            LEFT JOIN warehouse_storage_cells AS cell
                ON cell.warehouse_id = zone.warehouse_id
                AND cell.code = zone.code
            WHERE cell.id IS NULL
            SQL);

        DB::statement(<<<'SQL'
            WITH matched_cells AS (
                SELECT
                    balance.id AS record_id,
                    MIN(cell.id) AS cell_id
                FROM warehouse_balances AS balance
                INNER JOIN warehouse_storage_cells AS cell
                    ON cell.warehouse_id = balance.warehouse_id
                    AND cell.code = BTRIM(balance.location_code)
                WHERE balance.cell_id IS NULL
                    AND NULLIF(BTRIM(balance.location_code), '') IS NOT NULL
                GROUP BY balance.id
                HAVING COUNT(*) = 1
            )
            UPDATE warehouse_balances AS balance
            SET cell_id = matched_cells.cell_id
            FROM matched_cells
            WHERE balance.id = matched_cells.record_id
            SQL);

        DB::statement(<<<'SQL'
            WITH matched_cells AS (
                SELECT
                    item.id AS record_id,
                    MIN(cell.id) AS cell_id
                FROM inventory_act_items AS item
                INNER JOIN inventory_acts AS act
                    ON act.id = item.inventory_act_id
                INNER JOIN warehouse_storage_cells AS cell
                    ON cell.warehouse_id = act.warehouse_id
                    AND cell.code = BTRIM(item.location_code)
                WHERE item.cell_id IS NULL
                    AND NULLIF(BTRIM(item.location_code), '') IS NOT NULL
                GROUP BY item.id
                HAVING COUNT(*) = 1
            )
            UPDATE inventory_act_items AS item
            SET cell_id = matched_cells.cell_id
            FROM matched_cells
            WHERE item.id = matched_cells.record_id
            SQL);

        DB::statement(<<<'SQL'
            WITH matched_cells AS (
                SELECT
                    movement.id AS record_id,
                    MIN(cell.id) AS cell_id
                FROM warehouse_movements AS movement
                INNER JOIN warehouse_storage_cells AS cell
                    ON cell.warehouse_id = movement.warehouse_id
                    AND cell.code = BTRIM(movement.metadata->>'location_code')
                WHERE movement.cell_id IS NULL
                    AND NULLIF(BTRIM(movement.metadata->>'location_code'), '') IS NOT NULL
                GROUP BY movement.id
                HAVING COUNT(*) = 1
            )
            UPDATE warehouse_movements AS movement
            SET cell_id = matched_cells.cell_id
            FROM matched_cells
            WHERE movement.id = matched_cells.record_id
            SQL);
    }

    public function down(): void
    {
        Schema::table('warehouse_movements', function (Blueprint $table): void {
            $table->dropIndex('warehouse_movements_wh_cell_date_idx');
            $table->dropConstrainedForeignId('cell_id');
        });

        Schema::table('inventory_act_items', function (Blueprint $table): void {
            $table->dropIndex('inventory_act_items_act_cell_idx');
            $table->dropConstrainedForeignId('cell_id');
        });

        Schema::table('warehouse_balances', function (Blueprint $table): void {
            $table->dropIndex('warehouse_balances_warehouse_cell_idx');
            $table->dropConstrainedForeignId('cell_id');
        });

        Schema::table('warehouse_storage_cells', function (Blueprint $table): void {
            $table->dropColumn('storage_conditions');
        });
    }
};
