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

        $definition = $this->definition('public.eg_expected_package_item_price_v8(bigint)');
        $definition = $this->replace($definition, 'FUNCTION public.eg_expected_package_item_price_v8(', 'FUNCTION public.eg_expected_package_item_price_v9(');
        $definition = $this->replace($definition, '|semantic_project_resource:v8:', '|semantic_project_resource:v9:');
        $definition = $this->replace($definition, "'semantic_project_resource:v8'", "'semantic_project_resource:v9'");
        $pattern = '/round\(sum\((nr\.quantity.*?rp\.base_price)\),\s*2\)/is';
        if (preg_match($pattern, $definition, $match) !== 1) {
            throw new RuntimeException('estimate_generation.resource_rounding_expression_contract_changed');
        }
        $definition = preg_replace($pattern, 'sum(round($1,2))', $definition, -1, $count);
        if (! is_string($definition) || $count !== 1) {
            throw new RuntimeException('estimate_generation.resource_rounding_total_contract_changed');
        }
        $definition = $this->replace($definition, "'pricing_formula_version','semantic_project_resource:v9',",
            "'pricing_formula_version','semantic_project_resource:v9','rounding_policy','resource_half_up_2',");
        $definition = $this->replace($definition, "'base_price',rp.base_price::text,",
            "'base_price',rp.base_price::text,'final_amount',to_char(round(".$match[1].",2),'FM999999999999999999999999990.00'),");
        DB::unprepared($definition);

        $supplementary = $this->definition('public.eg_expected_package_item_price_v4(bigint)');
        $supplementary = $this->replace($supplementary, 'FUNCTION public.eg_expected_package_item_price_v4(', 'FUNCTION public.eg_expected_package_item_price_supplementary_v5(');
        $supplementary = $this->replace($supplementary, 'public.eg_expected_package_item_price_closed_v3(p_item_id)', 'public.eg_expected_package_item_price_closed_v9(p_item_id)');
        $supplementary = $this->replace($supplementary, '|supplementary_project_material:v4|', '|supplementary_project_material:v5|');
        $supplementary = $this->replace($supplementary, "'supplementary_project_material:v4'", "'supplementary_project_material:v5'");
        $pattern = '/round\(sum\((quantity\*r\.quantity_per_work_unit.*?rp\.base_price)\),\s*2\)/is';
        if (preg_match($pattern, $supplementary, $match) !== 1) {
            throw new RuntimeException('estimate_generation.project_resource_rounding_expression_contract_changed');
        }
        $supplementary = preg_replace($pattern, 'sum(round($1,2))', $supplementary, -1, $count);
        if (! is_string($supplementary) || $count !== 1) {
            throw new RuntimeException('estimate_generation.project_resource_rounding_total_contract_changed');
        }
        $supplementary = $this->replace($supplementary, "'base_price',rp.base_price::text,",
            "'base_price',rp.base_price::text,'final_amount',to_char(round(".$match[1].",2),'FM999999999999999999999999990.00'),");
        DB::unprepared($supplementary);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v9(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v9(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_supplementary_v5(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_supplementary_v5(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v9(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v9(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_supplementary_v5(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_supplementary_v5(bigint) FROM PUBLIC;
SQL);
        $this->route(true);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if ((bool) DB::scalar(<<<'SQL'
SELECT EXISTS (SELECT 1 FROM public.estimate_generation_package_items
WHERE price_snapshot->'coefficients'->>'pricing_formula_version' IN ('semantic_project_resource:v9','supplementary_project_material:v5'))
SQL)) {
            throw new RuntimeException('estimate_generation.resource_rounding_rollback_blocked');
        }
        $this->route(false);
        DB::unprepared(<<<'SQL'
DROP FUNCTION public.eg_expected_package_item_price_closed_supplementary_v5(bigint);
DROP FUNCTION public.eg_expected_package_item_price_supplementary_v5(bigint);
DROP FUNCTION public.eg_expected_package_item_price_closed_v9(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v9(bigint);
SQL);
    }

    private function route(bool $forward): void
    {
        $finalizer = $this->definition('public.eg_finalize_package_item_price(bigint)');
        foreach (['v8' => 'v9', 'v4' => 'supplementary_v5'] as $old => $new) {
            $before = 'public.eg_expected_package_item_price_closed_'.$old.'(p_item_id)';
            $after = 'public.eg_expected_package_item_price_closed_'.$new.'(p_item_id)';
            $finalizer = $this->replace($finalizer, $forward ? $before : $after, $forward ? $after : $before);
        }
        DB::unprepared($finalizer);
        foreach (['public.eg_package_item_price_validate()' => ['expected:=', 'NEW.id'],
            'public.eg_price_input_deferred_validate()' => ['PERFORM ', 'item_id']] as $signature => [$operation, $argument]) {
            $definition = $this->definition($signature);
            $before = "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v8' THEN";
            $after = "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v9' THEN\n"
                .$operation.'public.eg_expected_package_item_price_closed_v9('.$argument.");\n"
                ."ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v5' THEN\n"
                .$operation.'public.eg_expected_package_item_price_closed_supplementary_v5('.$argument.");\n"
                .'ELS'.$before;
            $definition = $this->replace($definition, $forward ? $before : $after, $forward ? $after : $before);
            DB::unprepared($definition);
        }
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(to_regprocedure(?))', [$signature]);
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.resource_rounding_function_missing');
        }

        return $definition;
    }

    private function replace(string $definition, string $before, string $after): string
    {
        $definition = str_replace($before, $after, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('estimate_generation.resource_rounding_contract_changed');
        }

        return $definition;
    }
};
