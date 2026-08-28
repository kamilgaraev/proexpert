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
        Schema::table('inventory_acts', function (Blueprint $table): void {
            $table->dropUnique('inventory_acts_act_number_unique');
            $table->unique(['organization_id', 'act_number'], 'inventory_acts_org_number_unique');
        });

        Schema::table('inventory_act_items', function (Blueprint $table): void {
            $table->dropUnique('unique_act_material_batch');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX inventory_act_items_position_unique
            ON inventory_act_items (
                inventory_act_id,
                material_id,
                cell_id,
                location_code,
                batch_number,
                unit_price
            ) NULLS NOT DISTINCT
            SQL);
    }

    public function down(): void
    {
        $hasActNumberConflicts = DB::table('inventory_acts')
            ->select('act_number')
            ->groupBy('act_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasActItemConflicts = DB::table('inventory_act_items')
            ->select(['inventory_act_id', 'material_id', 'batch_number'])
            ->groupBy(['inventory_act_id', 'material_id', 'batch_number'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasActNumberConflicts || $hasActItemConflicts) {
            throw new LogicException('Inventory identity rollback would discard supported tenant or stock-position identities.');
        }

        DB::statement('DROP INDEX IF EXISTS inventory_act_items_position_unique');

        Schema::table('inventory_act_items', function (Blueprint $table): void {
            $table->unique(['inventory_act_id', 'material_id', 'batch_number'], 'unique_act_material_batch');
        });

        Schema::table('inventory_acts', function (Blueprint $table): void {
            $table->dropUnique('inventory_acts_org_number_unique');
            $table->unique('act_number', 'inventory_acts_act_number_unique');
        });
    }
};
