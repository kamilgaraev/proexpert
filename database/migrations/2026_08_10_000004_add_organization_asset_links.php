<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'machinery_assets',
        'machinery_assignments',
        'machinery_shift_reports',
        'machinery_downtimes',
        'machinery_fuel_issues',
        'machinery_production_records',
        'machinery_maintenance_orders',
        'warehouse_movements',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'organization_asset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('organization_asset_id')
                    ->nullable()
                    ->constrained('organization_assets')
                    ->nullOnDelete();
            });
        }

        $this->installWarehouseMovementIdentityGuard(useJsonbComparison: true);
    }

    public function down(): void
    {
        $this->installWarehouseMovementIdentityGuard(useJsonbComparison: false);

        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'organization_asset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('organization_asset_id');
            });
        }
    }

    private function installWarehouseMovementIdentityGuard(bool $useJsonbComparison): void
    {
        if (
            DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('warehouse_inventory_events')
            || ! Schema::hasTable('warehouse_movements')
        ) {
            return;
        }

        $metadataComparison = $useJsonbComparison
            ? 'to_jsonb(NEW.metadata) IS DISTINCT FROM to_jsonb(OLD.metadata)'
            : 'NEW.metadata IS DISTINCT FROM OLD.metadata';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION most_warehouse_reporting_movement_identity_v1() RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1
                      FROM warehouse_inventory_events
                     WHERE source_movement_id = OLD.id
                ) AND (
                    NEW.organization_id <> OLD.organization_id
                    OR NEW.warehouse_id <> OLD.warehouse_id
                    OR NEW.material_id <> OLD.material_id
                    OR NEW.movement_type <> OLD.movement_type
                    OR NEW.quantity <> OLD.quantity
                    OR NEW.movement_date <> OLD.movement_date
                    OR NEW.from_warehouse_id IS DISTINCT FROM OLD.from_warehouse_id
                    OR NEW.to_warehouse_id IS DISTINCT FROM OLD.to_warehouse_id
                    OR NEW.operation_category IS DISTINCT FROM OLD.operation_category
                    OR NEW.project_material_delivery_id IS DISTINCT FROM OLD.project_material_delivery_id
                    OR NEW.price IS DISTINCT FROM OLD.price
                    OR {$metadataComparison}
                ) THEN
                    RAISE EXCEPTION 'linked warehouse movement identity is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END
            \$\$;
            SQL);
    }
};
