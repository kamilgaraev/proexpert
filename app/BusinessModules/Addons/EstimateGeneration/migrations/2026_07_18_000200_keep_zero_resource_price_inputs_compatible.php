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

        $definition = DB::scalar(
            "SELECT pg_get_functiondef('public.eg_expected_package_item_price(bigint)'::regprocedure)",
        );
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.expected_price_function_missing');
        }

        $positiveCount = 'SELECT count(*) INTO actual_count FROM public.estimate_generation_package_item_price_inputs inputs JOIN public.estimate_norm_resources counted_resources ON counted_resources.id = inputs.norm_resource_id WHERE inputs.package_item_id = item.id AND counted_resources.quantity > 0;';
        $positiveCountPattern = '/INTO\s+actual_count.+JOIN\s+public\.estimate_norm_resources\s+counted_resources.+counted_resources\.quantity\s*>\s*0\s*;/is';
        if (preg_match($positiveCountPattern, $definition) === 1) {
            return;
        }

        $updated = preg_replace(
            '/SELECT\s+count\(\*\)\s+INTO\s+actual_count\s+FROM\s+public\.estimate_generation_package_item_price_inputs\s+WHERE\s+package_item_id\s*=\s*item\.id\s*;/i',
            $positiveCount,
            $definition,
            1,
            $replacements,
        );
        if (! is_string($updated) || $replacements !== 1) {
            throw new RuntimeException('estimate_generation.actual_price_resource_count_contract_changed');
        }

        DB::unprepared($updated);
    }

    public function down(): void {}
};
