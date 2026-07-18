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

        $this->replaceFunction('public.eg_expected_package_item_price(bigint)', [
            '/(WHERE\s+estimate_norm_id\s*=\s*item\.estimate_norm_id\s+AND\s+quantity\s*>\s*0)\s*;/i' => '$1 AND resource_type <> \'summary\';',
            '/(counted_resources\.quantity\s*>\s*0)\s*;/i' => '$1 AND counted_resources.resource_type <> \'summary\';',
            '/WHERE\s+i\.package_item_id\s*=\s*item\.id\s*;/i' => "WHERE i.package_item_id = item.id AND nr.quantity > 0 AND nr.resource_type <> 'summary';",
        ]);
        $this->replaceFunction('public.eg_pricing_provenance(bigint)', [
            '/WHERE\s+i\.package_item_id\s*=\s*p_item_id\s*;/i' => "WHERE i.package_item_id = p_item_id AND nr.quantity > 0 AND nr.resource_type <> 'summary';",
        ]);
    }

    public function down(): void {}

    private function replaceFunction(string $signature, array $replacements): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }
        if (str_contains($definition, "resource_type <> 'summary'")) {
            return;
        }

        foreach ($replacements as $pattern => $replacement) {
            $definition = preg_replace($pattern, $replacement, $definition, 1, $count);
            if (! is_string($definition) || $count !== 1) {
                throw new RuntimeException('estimate_generation.summary_resource_contract_changed');
            }
        }

        DB::unprepared($definition);
    }
};
