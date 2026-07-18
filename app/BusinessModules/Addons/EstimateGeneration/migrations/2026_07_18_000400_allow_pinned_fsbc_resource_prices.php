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

        $this->allowBasePricesInExpectedPrice();
        $this->retainBasePricesInProvenance();
    }

    public function down(): void {}

    private function allowBasePricesInExpectedPrice(): void
    {
        $definition = $this->definition('public.eg_expected_package_item_price(bigint)');
        if (str_contains($definition, 'estimate_generation.base_price_input_mismatch')) {
            return;
        }

        $guard = <<<'SQL'
IF EXISTS (
    SELECT 1 FROM public.estimate_generation_package_item_price_inputs base_inputs
    JOIN public.estimate_resource_prices base_prices ON base_prices.id=base_inputs.resource_price_id
    JOIN public.estimate_dataset_versions base_datasets ON base_datasets.id=base_prices.dataset_version_id
    WHERE base_inputs.package_item_id=item.id AND base_prices.regional_price_version_id IS NULL
      AND (base_prices.region_id IS NOT NULL OR base_prices.price_zone_id IS NOT NULL OR base_prices.period_id IS NOT NULL
        OR base_datasets.status <> 'parsed' OR base_datasets.source_type NOT IN ('fsbc','fsnb_2022'))
  ) THEN RAISE EXCEPTION 'estimate_generation.base_price_input_mismatch'; END IF;
  IF EXISTS (
SQL;
        $updated = preg_replace('/IF\s+EXISTS\s*\(/', $guard, $definition, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException('estimate_generation.base_price_guard_contract_changed');
        }

        DB::unprepared($updated);
    }

    private function retainBasePricesInProvenance(): void
    {
        $definition = $this->definition('public.eg_pricing_provenance(bigint)');
        if (str_contains($definition, 'LEFT JOIN public.estimate_regional_price_versions rv')) {
            return;
        }

        $updated = preg_replace(
            '/JOIN\s+public\.estimate_regional_price_versions\s+rv\s+ON\s+rv\.id\s*=\s*rp\.regional_price_version_id/i',
            'LEFT JOIN public.estimate_regional_price_versions rv ON rv.id=rp.regional_price_version_id',
            $definition,
            1,
            $count,
        );
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException('estimate_generation.base_price_provenance_contract_changed');
        }

        DB::unprepared($updated);
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }

        return $definition;
    }
};
