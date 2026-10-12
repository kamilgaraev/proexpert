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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Estimate money components require PostgreSQL.');
        }
        if (Schema::hasColumn('estimate_items', 'other_cost')) {
            if (! Schema::hasColumn('estimates', 'total_other_costs') || ! Schema::hasColumn('estimates', 'contingency_amount')) {
                throw new RuntimeException('Estimate money component migration is incomplete.');
            }

            return; // The module wrapper can run before the root migration in the shared migrator.
        }
        Schema::table('estimate_items', function (Blueprint $table): void {
            $table->decimal('other_cost', 15, 2)->default(0);
        });
        Schema::table('estimates', function (Blueprint $table): void {
            $table->decimal('total_other_costs', 15, 2)->default(0);
            $table->decimal('contingency_amount', 15, 2)->default(0);
        });
        DB::statement('ALTER TABLE estimate_items DROP CONSTRAINT estimate_items_item_type_check');
        DB::statement("ALTER TABLE estimate_items ADD CONSTRAINT estimate_items_item_type_check CHECK (item_type IN ('work','material','equipment','machinery','labor','summary','other'))");
        DB::statement('ALTER TABLE estimate_item_resources DROP CONSTRAINT estimate_item_resources_resource_type_check');
        DB::statement("ALTER TABLE estimate_item_resources ADD CONSTRAINT estimate_item_resources_resource_type_check CHECK (resource_type IN ('material','labor','equipment','machinery','overhead','other'))");
        DB::statement('ALTER TABLE estimate_item_resources ALTER COLUMN quantity_per_unit TYPE numeric(30,18), ALTER COLUMN total_quantity TYPE numeric(30,18), ALTER COLUMN unit_price TYPE numeric(30,12)');
        DB::statement('ALTER TABLE estimate_items ADD CONSTRAINT estimate_items_other_cost_nonnegative CHECK (other_cost >= 0)');
        DB::statement('ALTER TABLE estimates ADD CONSTRAINT estimates_extra_costs_nonnegative CHECK (total_other_costs >= 0 AND contingency_amount >= 0)');
        // Historical equipment resources remain equipment; their meaning cannot be inferred safely.
    }

    public function down(): void
    {
        throw new RuntimeException('Estimate money history is forward-only; stop new operations for rollback.');
    }
};
