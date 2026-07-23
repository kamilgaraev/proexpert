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

        $definition = $this->definition('public.eg_expected_package_item_price_v5(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+public\.eg_expected_package_item_price_v5\(/i',
            'CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v6(',
            'estimate_generation.semantic_resource_payload_function_name_contract_changed',
        );
        $definition = str_replace('item.resources->', '(item.resources::jsonb)->', $definition, $payloadCastCount);
        $definition = str_replace('|semantic_project_resource:v5:', '|semantic_project_resource:v6:', $definition, $canonicalCount);
        $definition = str_replace("'semantic_project_resource:v5'", "'semantic_project_resource:v6'", $definition, $formulaVersionCount);
        if ($payloadCastCount !== 4 || $canonicalCount !== 1 || $formulaVersionCount !== 1) {
            throw new RuntimeException('estimate_generation.semantic_resource_payload_contract_changed');
        }
        DB::unprepared($definition);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v6(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v6(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v6(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v6(bigint) FROM PUBLIC;
SQL);

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v5\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v6(p_item_id); END IF;',
            'estimate_generation.semantic_resource_payload_finalize_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v5' THEN/i",
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v6' THEN\n"
            ."    expected:=public.eg_expected_package_item_price_closed_v6(NEW.id);\n"
            ."  ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v5' THEN",
            'estimate_generation.semantic_resource_payload_validator_contract_changed',
        );
        DB::unprepared($validator);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ((bool) DB::scalar(<<<'SQL'
SELECT EXISTS (
  SELECT 1 FROM public.estimate_generation_package_items
  WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v6'
)
SQL)) {
            throw new RuntimeException('estimate_generation.semantic_resource_payload_rollback_blocked');
        }

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v6\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v5(p_item_id); END IF;',
            'estimate_generation.semantic_resource_payload_finalize_rollback_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v6' THEN\s+expected:=public\.eg_expected_package_item_price_closed_v6\(NEW\.id\);\s+ELSIF/i",
            'IF',
            'estimate_generation.semantic_resource_payload_validator_rollback_contract_changed',
        );
        DB::unprepared($validator);
        DB::unprepared(<<<'SQL'
DROP FUNCTION public.eg_expected_package_item_price_closed_v6(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v6(bigint);
SQL);
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
