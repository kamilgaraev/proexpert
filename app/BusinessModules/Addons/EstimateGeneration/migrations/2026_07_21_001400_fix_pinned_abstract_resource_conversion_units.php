<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $definition = DB::scalar("SELECT pg_get_functiondef('public.eg_expected_package_item_price_v8(bigint)'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new \RuntimeException('estimate_generation.pinned_abstract_resource_conversion_function_missing');
        }

        $updated = preg_replace(
            '/OR nr\.unit IS DISTINCT FROM arc\.from_unit\s+OR rp\.unit IS DISTINCT FROM arc\.to_unit/i',
            "OR nr.unit IS DISTINCT FROM arc.to_unit\n          OR rp.unit IS DISTINCT FROM arc.from_unit",
            $definition,
            1,
            $count,
        );
        if (! is_string($updated) || $count !== 1) {
            throw new \RuntimeException('estimate_generation.pinned_abstract_resource_conversion_unit_contract_changed');
        }

        DB::unprepared($updated);
    }

    public function down(): void
    {
    }
};
