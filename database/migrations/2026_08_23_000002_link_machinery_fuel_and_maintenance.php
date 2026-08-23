<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPEN_MAINTENANCE_INDEX = 'machinery_maintenance_one_open_per_asset';

    public function up(): void
    {
        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->foreignId('shift_report_id')->nullable()->after('project_id')->constrained('machinery_shift_reports')->restrictOnDelete();
            $table->foreignId('operator_user_id')->nullable()->after('issued_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('operator_user_id')->constrained('organization_warehouses')->restrictOnDelete();
            $table->foreignId('material_id')->nullable()->after('warehouse_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('warehouse_movement_id')->nullable()->after('material_id')->constrained('warehouse_movements')->restrictOnDelete();
            $table->unique('warehouse_movement_id', 'machinery_fuel_movement_unique');
            $table->index(['shift_report_id', 'issued_at'], 'machinery_fuel_shift_issued_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "CREATE UNIQUE INDEX %s ON machinery_maintenance_orders (asset_id) WHERE status IN ('open', 'in_progress') AND deleted_at IS NULL",
                self::OPEN_MAINTENANCE_INDEX,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::OPEN_MAINTENANCE_INDEX);
        }

        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->dropUnique('machinery_fuel_movement_unique');
            $table->dropIndex('machinery_fuel_shift_issued_index');
            $table->dropConstrainedForeignId('warehouse_movement_id');
            $table->dropConstrainedForeignId('material_id');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('operator_user_id');
            $table->dropConstrainedForeignId('shift_report_id');
        });
    }
};
