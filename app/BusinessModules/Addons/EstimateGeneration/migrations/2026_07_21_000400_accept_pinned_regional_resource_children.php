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

        $definition = $this->definition('public.eg_expected_package_item_price_v6(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+public\.eg_expected_package_item_price_v6\(/i',
            'CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v7(',
            'estimate_generation.pinned_resource_child_function_name_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/CASE WHEN LOWER\(COALESCE\(nr\.raw_payload->>'source_tag', ''\)\) = 'abstractresource' THEN.*?ELSE rp\.resource_code IS DISTINCT FROM nr\.resource_code END/is",
            <<<'SQL'
CASE WHEN nr.resource_code ~ '^[0-9]{2}\.[0-9]\.[0-9]{2}\.[0-9]{2}$' THEN
        rp.resource_code !~ ('^'||replace(nr.resource_code, '.', '\.')||'-[0-9]{4}$')
        AND NOT EXISTS (
          SELECT 1
          FROM jsonb_array_elements(
            COALESCE((item.resources::jsonb)->'materials', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'labor', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'machinery', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'other', '[]'::jsonb)
          ) AS persisted_resource(value)
          WHERE persisted_resource.value->'normative_ref'->>'norm_resource_id' = nr.id::text
            AND persisted_resource.value->'normative_ref'->>'price_id' = rp.id::text
            AND persisted_resource.value->'normative_ref'->'project_resource_selection'->>'selected_resource_code' = rp.resource_code
            AND persisted_resource.value->'normative_ref'->'project_resource_selection'->>'policy' IN (
              'regional_semantic_pipe_hard_attributes_median:v1',
              'regional_semantic_metal_gutter_family_median:v1',
              'regional_semantic_hard_attributes_median:v2',
              'regional_semantic_hard_attributes_median:v3',
              'regional_semantic_hard_attributes_median:v4'
            )
            AND (persisted_resource.value->'normative_ref'->'project_resource_selection'->>'candidates_count') ~ '^[1-9][0-9]*$'
        )
      ELSE rp.resource_code IS DISTINCT FROM nr.resource_code END
SQL,
            'estimate_generation.pinned_resource_child_code_contract_changed',
        );
        $definition = str_replace('|semantic_project_resource:v6:', '|semantic_project_resource:v7:', $definition, $canonicalCount);
        $definition = str_replace("'semantic_project_resource:v6'", "'semantic_project_resource:v7'", $definition, $formulaVersionCount);
        if ($canonicalCount !== 1 || $formulaVersionCount !== 1) {
            throw new RuntimeException('estimate_generation.pinned_resource_child_version_contract_changed');
        }
        DB::unprepared($definition);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v7(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v7(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v7(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v7(bigint) FROM PUBLIC;
SQL);

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v6\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v7(p_item_id); END IF;',
            'estimate_generation.pinned_resource_child_finalize_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v6' THEN/i",
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v7' THEN\n"
            ."    expected:=public.eg_expected_package_item_price_closed_v7(NEW.id);\n"
            ."  ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v6' THEN",
            'estimate_generation.pinned_resource_child_validator_contract_changed',
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
  WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v7'
)
SQL)) {
            throw new RuntimeException('estimate_generation.pinned_resource_child_rollback_blocked');
        }

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v7\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v6(p_item_id); END IF;',
            'estimate_generation.pinned_resource_child_finalize_rollback_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v7' THEN\s+expected:=public\.eg_expected_package_item_price_closed_v7\(NEW\.id\);\s+ELSIF/i",
            'IF',
            'estimate_generation.pinned_resource_child_validator_rollback_contract_changed',
        );
        DB::unprepared($validator);
        DB::unprepared(<<<'SQL'
DROP FUNCTION public.eg_expected_package_item_price_closed_v7(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v7(bigint);
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
