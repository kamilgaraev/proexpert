<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'estimate_generation_package_items';

    private const COSTS = ['direct_cost', 'overhead_cost', 'profit_cost', 'total_cost'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COSTS as $column) {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN '.$column.' DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COSTS as $column) {
            if (DB::table(self::TABLE)->whereNull($column)->exists()) {
                throw new \RuntimeException('estimate_generation.unknown_review_cost_rollback_unsafe');
            }
        }
        foreach (self::COSTS as $column) {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN '.$column.' SET NOT NULL');
        }
    }
};
