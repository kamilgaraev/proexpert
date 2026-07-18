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

        $this->createVersionedExpectedPrice();
        $this->useVersionedPriceForNewItems();
        $this->validateEachPricingFormulaWithItsOwnVersion();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasVersionedPrices = DB::scalar(<<<'SQL'
SELECT EXISTS (
  SELECT 1 FROM public.estimate_generation_package_items
  WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='project_resource:v3'
)
SQL);
        if ((bool) $hasVersionedPrices) {
            throw new RuntimeException('estimate_generation.project_resource_formula_rollback_blocked');
        }

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/public\.eg_expected_package_item_price_closed_v3\(p_item_id\)/',
            'public.eg_expected_package_item_price_closed_v2(p_item_id)',
            'estimate_generation.project_resource_finalize_rollback_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF\s+current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='project_resource:v3'\s+THEN\s+expected:=public\.eg_expected_package_item_price_closed_v3\(NEW\.id\);\s+ELSIF/i",
            'IF',
            'estimate_generation.project_resource_validator_rollback_contract_changed',
        );
        DB::unprepared($validator);

        DB::unprepared(<<<'SQL'
DROP FUNCTION public.eg_expected_package_item_price_closed_v3(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v3(bigint);
SQL);
    }

    private function createVersionedExpectedPrice(): void
    {
        $definition = $this->definition('public.eg_expected_package_item_price_v2(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+public\.eg_expected_package_item_price_v2\(/i',
            'CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v3(',
            'estimate_generation.project_resource_function_name_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            '/rp\.resource_code\s+IS\s+DISTINCT\s+FROM\s+nr\.resource_code/i',
            "CASE WHEN LOWER(COALESCE(nr.raw_payload->>'source_tag', '')) = 'abstractresource'\n"
                ."THEN nr.resource_code !~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}$'\n"
                ."  OR rp.resource_code !~ ('^'||replace(nr.resource_code, '.', '\\.')||'-[0-9]{4}$')\n"
                .'ELSE rp.resource_code IS DISTINCT FROM nr.resource_code END',
            'estimate_generation.project_resource_code_contract_changed',
        );
        $definition = str_replace(
            '|norm_measurement:v2:',
            '|project_resource:v3:',
            $definition,
            $canonicalVersionCount,
        );
        $definition = str_replace(
            "'norm_measurement:v2'",
            "'project_resource:v3'",
            $definition,
            $formulaVersionCount,
        );
        if ($canonicalVersionCount !== 1 || $formulaVersionCount !== 1) {
            throw new RuntimeException('estimate_generation.project_resource_formula_version_contract_changed');
        }
        DB::unprepared($definition);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v3(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v3(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v3(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v3(bigint) FROM PUBLIC;
SQL);
    }

    private function useVersionedPriceForNewItems(): void
    {
        $definition = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/public\.eg_expected_package_item_price_closed_v2\(p_item_id\)/',
            'public.eg_expected_package_item_price_closed_v3(p_item_id)',
            'estimate_generation.project_resource_finalize_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function validateEachPricingFormulaWithItsOwnVersion(): void
    {
        $definition = $this->definition('public.eg_package_item_price_validate()');
        $definition = $this->replaceOnce(
            $definition,
            "/IF\s+current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='norm_measurement:v2'\s+THEN/i",
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='project_resource:v3' THEN\n"
                ."    expected:=public.eg_expected_package_item_price_closed_v3(NEW.id);\n"
                ."  ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='norm_measurement:v2' THEN",
            'estimate_generation.project_resource_validator_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }

        return $definition;
    }

    private function replaceOnce(string $source, string $pattern, string $replacement, string $error): string
    {
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException($error);
        }

        return $updated;
    }
};
