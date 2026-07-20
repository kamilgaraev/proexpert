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

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_project_material_rule_immutable ON public.estimate_generation_project_material_rules;
ALTER TABLE public.estimate_generation_project_material_rules ADD COLUMN scenario_assumption_code varchar(180);
UPDATE public.estimate_generation_project_material_rules SET scenario_assumption_code=CASE work_item_key
  WHEN 'electrical.main_cable' THEN 'residential_feeder_cable_clips'
  WHEN 'electrical.power_lines' THEN 'residential_power_wiring_channels'
  WHEN 'lighting.lines' THEN 'residential_lighting_wiring_chases'
  WHEN 'electrical.panel' THEN 'residential_recessed_lighting_panel'
  WHEN 'electrical.outlets' THEN 'residential_recessed_socket'
  WHEN 'electrical.switches' THEN 'residential_recessed_single_switch'
  WHEN 'lighting.fixtures' THEN 'residential_ceiling_luminaire'
END
WHERE catalog_version='residential_project_material:v3';
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM public.estimate_generation_project_material_rules WHERE scenario_assumption_code IS NULL)
  THEN RAISE EXCEPTION 'estimate_generation.project_material_scenario_assumption_backfill_incomplete'; END IF;
END $$;
ALTER TABLE public.estimate_generation_project_material_rules ALTER COLUMN scenario_assumption_code SET NOT NULL;
CREATE TRIGGER eg_project_material_rule_immutable BEFORE UPDATE OR DELETE ON public.estimate_generation_project_material_rules
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_rule_immutable_guard();

CREATE OR REPLACE FUNCTION public.eg_project_material_price_mismatch_code_v4(p_item_id bigint) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT mismatch_code FROM (
  SELECT i.ordinal,CASE
    WHEN r.quantity_per_work_unit<=0 OR r.price_factor<=0 THEN 'invalid_rule_numeric'
    WHEN item.metadata->'specialization_scenario'->>'work_item_key' IS DISTINCT FROM r.work_item_key THEN 'scenario_work_item_key'
    WHEN item.metadata->'specialization_scenario'->>'assumption_code' IS DISTINCT FROM r.scenario_assumption_code THEN 'scenario_assumption_code'
    WHEN i.selection->>'version' IS DISTINCT FROM r.catalog_version THEN 'selection_catalog_version'
    WHEN i.selection->>'work_item_key' IS DISTINCT FROM r.work_item_key THEN 'selection_work_item_key'
    WHEN i.selection->>'assumption_code' IS DISTINCT FROM r.assumption_code THEN 'selection_material_assumption_code'
    WHEN i.selection->>'preferred_resource_code' IS DISTINCT FROM r.preferred_resource_code THEN 'selection_preferred_resource_code'
    WHEN i.selection->>'candidate_pool_version' IS DISTINCT FROM 'project_material_candidate_pool:v2' THEN 'selection_candidate_pool_version'
    WHEN i.selection->>'resource_code' IS DISTINCT FROM trim(rp.resource_code) THEN 'selected_resource_code'
    WHEN i.selection->>'resource_name' IS DISTINCT FROM trim(rp.resource_name) THEN 'selected_resource_name'
    WHEN i.selection->>'source_price_unit' IS DISTINCT FROM r.source_unit THEN 'selection_source_price_unit'
    WHEN i.selection->>'price_unit' IS DISTINCT FROM r.material_unit THEN 'selection_material_price_unit'
    WHEN (i.selection->>'source_unit_price')::numeric IS DISTINCT FROM rp.base_price THEN 'selection_source_unit_price'
    WHEN (i.selection->>'price_conversion_factor')::numeric IS DISTINCT FROM r.price_factor THEN 'selection_price_conversion_factor'
    WHEN trim(rp.unit) IS DISTINCT FROM trim(r.source_unit) OR rp.base_price IS NULL OR rp.base_price<=0 THEN 'catalog_resource_price'
    WHEN NOT (
      (rp.regional_price_version_id IS NOT NULL AND rp.region_id=item.region_id AND rp.price_zone_id=item.price_zone_id
        AND rp.period_id=item.period_id AND rp.regional_price_version_id=item.regional_price_version_id
        AND rv.status='active' AND i.selection->>'price_source'='regional_catalog'
        AND i.selection->>'price_source_version'=trim(rv.version_key))
      OR
      (rp.regional_price_version_id IS NULL AND rp.region_id IS NULL AND rp.price_zone_id IS NULL AND rp.period_id IS NULL
        AND dv.status='parsed' AND trim(dv.source_type) IN ('fsbc','fsnb_2022')
        AND i.selection->>'price_source'=CASE trim(dv.source_type) WHEN 'fsbc' THEN 'fsbc_base' ELSE 'fsnb_base' END
        AND i.selection->>'price_source_version'=trim(dv.version_key))
    ) THEN 'price_source_context'
    WHEN NOT (
      (i.selection->>'selection_policy'='exact_code' AND trim(rp.resource_code)=trim(r.preferred_resource_code)
        AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
      OR
      (i.selection->>'selection_policy'='semantic_group_median' AND r.fallback_group_code IS NOT NULL
        AND trim(rp.resource_code) ~ ('^'||replace(trim(r.fallback_group_code),'.','\.')||'-[0-9]{4}$')
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(r.fallback_name_markers) marker
          WHERE position(lower(marker) in lower(trim(rp.resource_name)))=0)
        AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
      OR
      (i.selection->>'selection_policy'='semantic_catalog_attributes_median'
        AND trim(rp.resource_code) ~ '^[0-9]{2}\.[0-9]\.[0-9]{2}\.[0-9]{2}-[0-9]{4}$'
        AND jsonb_array_length(r.semantic_name_markers)>0
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(r.semantic_name_markers) marker
          WHERE position(lower(marker) in lower(trim(rp.resource_name)))=0)
        AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
    ) THEN 'selection_policy_or_candidate'
  END AS mismatch_code
  FROM public.estimate_generation_package_item_project_price_inputs i
  JOIN public.estimate_generation_project_material_rules r ON r.id=i.project_material_rule_id
  JOIN public.estimate_generation_package_items item ON item.id=i.package_item_id
  JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
  LEFT JOIN public.estimate_regional_price_versions rv ON rv.id=rp.regional_price_version_id
  LEFT JOIN public.estimate_dataset_versions dv ON dv.id=rp.dataset_version_id
  WHERE i.package_item_id=p_item_id
) diagnostics WHERE mismatch_code IS NOT NULL ORDER BY ordinal LIMIT 1;
$$;
REVOKE ALL ON FUNCTION public.eg_project_material_price_mismatch_code_v4(bigint) FROM PUBLIC;
SQL);

        $this->replacePackagePriceFunction($this->forwardReplacements(), 'estimate_generation.project_material_scenario_boundary_contract_changed');
        $this->revokePublicExecution();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasSeparatedScenarioV4 = DB::scalar(<<<'SQL'
SELECT EXISTS (SELECT 1 FROM public.estimate_generation_package_items
WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v4')
SQL);
        if ((bool) $hasSeparatedScenarioV4) {
            throw new RuntimeException('estimate_generation.project_material_scenario_boundary_rollback_blocked');
        }

        $this->replacePackagePriceFunction(
            $this->reverse($this->forwardReplacements()),
            'estimate_generation.project_material_scenario_boundary_rollback_contract_changed',
        );
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS public.eg_project_material_price_mismatch_code_v4(bigint);
DROP TRIGGER IF EXISTS eg_project_material_rule_immutable ON public.estimate_generation_project_material_rules;
ALTER TABLE public.estimate_generation_project_material_rules DROP COLUMN scenario_assumption_code;
CREATE TRIGGER eg_project_material_rule_immutable BEFORE UPDATE OR DELETE ON public.estimate_generation_project_material_rules
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_rule_immutable_guard();
SQL);
        $this->revokePublicExecution();
    }

    /** @return array<string, string> */
    private function forwardReplacements(): array
    {
        return [
            "item.metadata->'specialization_scenario'->>'assumption_code' IS DISTINCT FROM r.assumption_code" => "item.metadata->'specialization_scenario'->>'assumption_code' IS DISTINCT FROM r.scenario_assumption_code",
            'project_canonical text;' => 'mismatch_code text; project_canonical text;',
            ") THEN RAISE EXCEPTION 'estimate_generation.project_material_price_input_mismatch'; END IF;" => ") THEN\n    mismatch_code:=public.eg_project_material_price_mismatch_code_v4(item.id);\n    RAISE EXCEPTION 'estimate_generation.project_material_price_input_mismatch:%',COALESCE(mismatch_code,'unknown');\n  END IF;",
            "r.catalog_version||':'||r.work_item_key||':'||r.assumption_code||':'||r.quantity_per_work_unit" => "r.catalog_version||':'||r.work_item_key||':'||r.scenario_assumption_code||':'||r.assumption_code||':'||r.quantity_per_work_unit",
            "'catalog_version',r.catalog_version,'work_item_key',r.work_item_key,'assumption_code',r.assumption_code," => "'catalog_version',r.catalog_version,'work_item_key',r.work_item_key,'scenario_assumption_code',r.scenario_assumption_code,'assumption_code',r.assumption_code,",
        ];
    }

    /** @param array<string, string> $replacements */
    private function replacePackagePriceFunction(array $replacements, string $error): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('public.eg_expected_package_item_price_v4(bigint)'::regprocedure)");
        if (! is_string($definition)
            || stripos($definition, 'SECURITY DEFINER') === false
            || stripos($definition, 'search_path') === false) {
            throw new RuntimeException($error);
        }
        foreach ($replacements as $search => $replacement) {
            if (substr_count($definition, $search) !== 1) {
                throw new RuntimeException($error);
            }
            $definition = str_replace($search, $replacement, $definition);
        }
        DB::unprepared($definition);
    }

    /** @param array<string, string> $replacements @return array<string, string> */
    private function reverse(array $replacements): array
    {
        $reversed = [];
        foreach ($replacements as $search => $replacement) {
            $reversed[$replacement] = $search;
        }

        return $reversed;
    }

    private function revokePublicExecution(): void
    {
        DB::unprepared(<<<'SQL'
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_project_material_price_mismatch_code_v4(bigint) FROM PUBLIC;
SQL);
    }
};
