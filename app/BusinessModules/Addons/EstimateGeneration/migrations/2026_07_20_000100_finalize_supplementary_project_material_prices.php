<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_project_material_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog_version', 80);
            $table->string('work_item_key', 120);
            $table->string('assumption_code', 180);
            $table->string('preferred_resource_code', 80);
            $table->string('fallback_group_code', 80)->nullable();
            $table->jsonb('fallback_name_markers');
            $table->jsonb('semantic_name_markers');
            $table->string('material_unit', 50);
            $table->string('source_unit', 50);
            $table->decimal('quantity_per_work_unit', 30, 12);
            $table->decimal('price_factor', 30, 12);
            $table->timestampsTz();
            $table->unique(['catalog_version', 'work_item_key'], 'eg_project_material_rule_version_key_uq');
            $table->unique(['catalog_version', 'assumption_code'], 'eg_project_material_rule_assumption_uq');
        });
        Schema::create('estimate_generation_package_item_project_price_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_item_id')->constrained('estimate_generation_package_items')->restrictOnDelete();
            $table->foreignId('project_material_rule_id')->constrained('estimate_generation_project_material_rules')->restrictOnDelete();
            $table->foreignId('resource_price_id')->constrained('estimate_resource_prices')->restrictOnDelete();
            $table->unsignedInteger('ordinal');
            $table->jsonb('selection');
            $table->timestampsTz();
            $table->unique(['package_item_id', 'project_material_rule_id'], 'eg_item_project_price_rule_uq');
            $table->unique(['package_item_id', 'ordinal'], 'eg_item_project_price_ordinal_uq');
        });

        DB::table('estimate_generation_project_material_rules')->insert($this->rules());

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_project_material_price_id_v4(p_input_id bigint) RETURNS bigint
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
WITH context AS (
  SELECT i.selection,r.fallback_group_code,r.fallback_name_markers,r.semantic_name_markers,r.source_unit,
    r.preferred_resource_code,p.region_id,p.price_zone_id,p.period_id,p.regional_price_version_id
  FROM public.estimate_generation_package_item_project_price_inputs i
  JOIN public.estimate_generation_project_material_rules r ON r.id=i.project_material_rule_id
  JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id
  WHERE i.id=p_input_id
), eligible AS (
  SELECT rp.id,rp.base_price,rp.resource_code,x.selection->>'selection_policy' AS selection_policy,
    CASE WHEN rp.regional_price_version_id IS NOT NULL THEN 0 ELSE 1 END AS source_priority,
    CASE dv.source_type WHEN 'fsbc' THEN 0 WHEN 'fsnb_2022' THEN 1 ELSE 2 END AS dataset_source_priority
  FROM context x
  JOIN public.estimate_resource_prices rp ON rp.unit=x.source_unit AND rp.base_price>0
  LEFT JOIN public.estimate_regional_price_versions rv ON rv.id=rp.regional_price_version_id
  LEFT JOIN public.estimate_dataset_versions dv ON dv.id=rp.dataset_version_id
  WHERE x.selection->>'selection_policy' IN ('exact_code','semantic_group_median','semantic_catalog_attributes_median')
    AND rp.id IN (SELECT CASE WHEN value ~ '^[1-9][0-9]*$' THEN value::bigint END
      FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(x.selection->'candidate_resource_price_ids')='array'
        THEN x.selection->'candidate_resource_price_ids' ELSE '[]'::jsonb END))
    AND (
      (rp.regional_price_version_id=x.regional_price_version_id AND rp.region_id=x.region_id
        AND rp.price_zone_id=x.price_zone_id AND rp.period_id=x.period_id AND rv.status='active')
      OR
      (rp.regional_price_version_id IS NULL AND rp.region_id IS NULL AND rp.price_zone_id IS NULL AND rp.period_id IS NULL
        AND dv.status='parsed' AND dv.source_type IN ('fsbc','fsnb_2022'))
    )
    AND (
      (x.selection->>'selection_policy'='exact_code' AND rp.resource_code=x.preferred_resource_code)
      OR
      (x.selection->>'selection_policy'='semantic_group_median' AND x.fallback_group_code IS NOT NULL
        AND rp.resource_code ~ ('^'||replace(x.fallback_group_code,'.','\.')||'-[0-9]{4}$')
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(x.fallback_name_markers) marker
          WHERE position(lower(marker) in lower(rp.resource_name))=0))
      OR
      (x.selection->>'selection_policy'='semantic_catalog_attributes_median'
        AND rp.resource_code ~ '^[0-9]{2}\.[0-9]\.[0-9]{2}\.[0-9]{2}-[0-9]{4}$'
        AND jsonb_array_length(x.semantic_name_markers)>0
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(x.semantic_name_markers) marker
          WHERE position(lower(marker) in lower(rp.resource_name))=0))
    )
), prioritized AS (
  SELECT * FROM eligible WHERE source_priority=(SELECT min(source_priority) FROM eligible)
), ranked AS (
  SELECT id,selection_policy,
    row_number() OVER (ORDER BY base_price,resource_code,dataset_source_priority,id DESC) AS semantic_rank,
    row_number() OVER (ORDER BY dataset_source_priority,id DESC) AS exact_rank,
    count(*) OVER () AS candidates_count
  FROM prioritized
)
SELECT id FROM ranked WHERE (selection_policy='exact_code' AND exact_rank=1)
  OR (selection_policy IN ('semantic_group_median','semantic_catalog_attributes_median')
    AND semantic_rank=((candidates_count+1)/2));
$$;

CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v4(p_item_id bigint) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE item record; base jsonb; project_count bigint; project_total numeric(30,2); total numeric(30,2);
        project_canonical text; project_resources jsonb; snapshot jsonb; canonical text; quantity numeric;
BEGIN
  SELECT * INTO item FROM public.estimate_generation_package_items WHERE id=p_item_id;
  IF item.id IS NULL OR item.pricing_finalized_at IS NULL THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
  base:=public.eg_expected_package_item_price_closed_v3(p_item_id);
  quantity:=(base->>'quantity')::numeric;
  SELECT count(*) INTO project_count FROM public.estimate_generation_package_item_project_price_inputs WHERE package_item_id=item.id;
  IF project_count=0 THEN RAISE EXCEPTION 'estimate_generation.project_material_input_missing'; END IF;
  IF EXISTS (
    SELECT 1
    FROM public.estimate_generation_package_item_project_price_inputs i
    JOIN public.estimate_generation_project_material_rules r ON r.id=i.project_material_rule_id
    JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
    LEFT JOIN public.estimate_regional_price_versions rv ON rv.id=rp.regional_price_version_id
    LEFT JOIN public.estimate_dataset_versions dv ON dv.id=rp.dataset_version_id
    WHERE i.package_item_id=item.id AND (
      r.quantity_per_work_unit<=0 OR r.price_factor<=0
      OR item.metadata->'specialization_scenario'->>'work_item_key' IS DISTINCT FROM r.work_item_key
      OR item.metadata->'specialization_scenario'->>'assumption_code' IS DISTINCT FROM r.assumption_code
      OR i.selection->>'version' IS DISTINCT FROM r.catalog_version
      OR i.selection->>'work_item_key' IS DISTINCT FROM r.work_item_key
      OR i.selection->>'assumption_code' IS DISTINCT FROM r.assumption_code
      OR i.selection->>'preferred_resource_code' IS DISTINCT FROM r.preferred_resource_code
      OR i.selection->>'candidate_pool_version' IS DISTINCT FROM 'project_material_candidate_pool:v2'
      OR i.selection->>'resource_code' IS DISTINCT FROM trim(rp.resource_code)
      OR i.selection->>'resource_name' IS DISTINCT FROM trim(rp.resource_name)
      OR i.selection->>'source_price_unit' IS DISTINCT FROM r.source_unit
      OR i.selection->>'price_unit' IS DISTINCT FROM r.material_unit
      OR (i.selection->>'source_unit_price')::numeric IS DISTINCT FROM rp.base_price
      OR (i.selection->>'price_conversion_factor')::numeric IS DISTINCT FROM r.price_factor
      OR rp.unit IS DISTINCT FROM r.source_unit OR rp.base_price IS NULL OR rp.base_price<=0
      OR NOT (
        (rp.regional_price_version_id IS NOT NULL AND rp.region_id=item.region_id AND rp.price_zone_id=item.price_zone_id
          AND rp.period_id=item.period_id AND rp.regional_price_version_id=item.regional_price_version_id
          AND rv.status='active' AND i.selection->>'price_source'='regional_catalog'
          AND i.selection->>'price_source_version'=rv.version_key)
        OR
        (rp.regional_price_version_id IS NULL AND rp.region_id IS NULL AND rp.price_zone_id IS NULL AND rp.period_id IS NULL
          AND dv.status='parsed' AND dv.source_type IN ('fsbc','fsnb_2022')
          AND i.selection->>'price_source'=CASE dv.source_type WHEN 'fsbc' THEN 'fsbc_base' ELSE 'fsnb_base' END
          AND i.selection->>'price_source_version'=dv.version_key)
      )
      OR NOT (
        (i.selection->>'selection_policy'='exact_code' AND rp.resource_code=r.preferred_resource_code
          AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
        OR
        (i.selection->>'selection_policy'='semantic_group_median' AND r.fallback_group_code IS NOT NULL
          AND rp.resource_code ~ ('^'||replace(r.fallback_group_code,'.','\.')||'-[0-9]{4}$')
          AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(r.fallback_name_markers) marker
            WHERE position(lower(marker) in lower(rp.resource_name))=0)
          AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
        OR
        (i.selection->>'selection_policy'='semantic_catalog_attributes_median'
          AND rp.resource_code ~ '^[0-9]{2}\.[0-9]\.[0-9]{2}\.[0-9]{2}-[0-9]{4}$'
          AND jsonb_array_length(r.semantic_name_markers)>0
          AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(r.semantic_name_markers) marker
            WHERE position(lower(marker) in lower(rp.resource_name))=0)
          AND rp.id=public.eg_expected_project_material_price_id_v4(i.id))
      )
    )
  ) THEN RAISE EXCEPTION 'estimate_generation.project_material_price_input_mismatch'; END IF;
  SELECT round(sum(quantity*r.quantity_per_work_unit*r.price_factor*rp.base_price),2),
    string_agg(r.id||':'||r.catalog_version||':'||r.work_item_key||':'||r.assumption_code||':'||r.quantity_per_work_unit||':'||r.price_factor||':'||
      rp.id||':'||rp.resource_code||':'||rp.unit||':'||rp.base_price||':'||i.selection::text,'|' ORDER BY i.ordinal),
    jsonb_agg(jsonb_build_object('input_kind','project_material','project_material_rule_id',r.id,
      'catalog_version',r.catalog_version,'work_item_key',r.work_item_key,'assumption_code',r.assumption_code,
      'quantity_per_work_unit',r.quantity_per_work_unit::text,'price_factor',r.price_factor::text,
      'resource_price_id',rp.id,'resource_code',trim(rp.resource_code),'resource_name',trim(rp.resource_name),
      'price_unit',rp.unit,'base_price',rp.base_price::text,'selection_policy',i.selection->>'selection_policy',
      'price_source',i.selection->>'price_source','price_source_version',i.selection->>'price_source_version') ORDER BY i.ordinal)
    INTO project_total,project_canonical,project_resources
  FROM public.estimate_generation_package_item_project_price_inputs i
  JOIN public.estimate_generation_project_material_rules r ON r.id=i.project_material_rule_id
  JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
  WHERE i.package_item_id=item.id;
  IF project_total IS NULL OR project_total<=0 THEN RAISE EXCEPTION 'estimate_generation.project_material_price_inputs_missing'; END IF;
  total:=round((base->>'money')::numeric+project_total,2);
  canonical:=(base->'snapshot'->>'source_reference')||'|supplementary_project_material:v4|'||project_canonical;
  snapshot:=jsonb_set(base->'snapshot','{source_reference}',to_jsonb('sha256:'||encode(pg_catalog.sha256(pg_catalog.convert_to(canonical,'UTF8')),'hex')),true);
  snapshot:=jsonb_set(snapshot,'{base_amount}',to_jsonb(to_char(total,'FM999999999999999999999999990.00')),true);
  snapshot:=jsonb_set(snapshot,'{final_amount}',to_jsonb(to_char(total,'FM999999999999999999999999990.00')),true);
  snapshot:=jsonb_set(snapshot,'{coefficients,pricing_formula_version}',to_jsonb('supplementary_project_material:v4'::text),true);
  snapshot:=jsonb_set(snapshot,'{coefficients,project_material_amount}',to_jsonb(to_char(project_total,'FM999999999999999999999999990.00')),true);
  snapshot:=jsonb_set(snapshot,'{coefficients,project_material_evidence}',project_resources,true);
  RETURN jsonb_build_object('quantity',quantity,'unit',base->>'unit','unit_price',round(total/quantity,6),
    'money',total,'snapshot',snapshot);
END; $$;

CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v4(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v4(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;

CREATE OR REPLACE FUNCTION public.eg_project_material_rule_immutable_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ BEGIN
  RAISE EXCEPTION 'estimate_generation.project_material_rule_is_immutable';
END; $$;
CREATE TRIGGER eg_project_material_rule_immutable BEFORE UPDATE OR DELETE ON public.estimate_generation_project_material_rules
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_rule_immutable_guard();

CREATE TRIGGER eg_project_price_input_append BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_generation_package_item_project_price_inputs
FOR EACH ROW EXECUTE FUNCTION public.eg_price_input_closed_guard();

CREATE OR REPLACE FUNCTION public.eg_project_material_price_reference_immutable_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ BEGIN
  IF EXISTS (
    SELECT 1 FROM public.estimate_generation_package_item_project_price_inputs i
    JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id
    WHERE p.pricing_finalized_at IS NOT NULL AND (
      i.resource_price_id=OLD.id OR EXISTS (
        SELECT 1 FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(i.selection->'candidate_resource_price_ids')='array'
          THEN i.selection->'candidate_resource_price_ids' ELSE '[]'::jsonb END) candidate
        WHERE CASE WHEN candidate ~ '^[1-9][0-9]*$' THEN candidate::bigint END=OLD.id
      )
    )
  ) THEN RAISE EXCEPTION 'estimate_generation.finalized_project_material_price_is_immutable'; END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE TRIGGER eg_finalized_project_material_price_immutable BEFORE UPDATE OR DELETE ON public.estimate_resource_prices
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_price_reference_immutable_guard();

CREATE OR REPLACE FUNCTION public.eg_project_material_dataset_reference_immutable_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ BEGIN
  IF EXISTS (
    SELECT 1 FROM public.estimate_generation_package_item_project_price_inputs i
    JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id
    JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(i.selection->'candidate_resource_price_ids')='array'
      THEN i.selection->'candidate_resource_price_ids' ELSE '[]'::jsonb END) candidate ON true
    JOIN public.estimate_resource_prices rp
      ON rp.id=CASE WHEN candidate ~ '^[1-9][0-9]*$' THEN candidate::bigint END
    WHERE p.pricing_finalized_at IS NOT NULL AND rp.dataset_version_id=OLD.id
  ) THEN RAISE EXCEPTION 'estimate_generation.finalized_project_material_dataset_is_immutable'; END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE TRIGGER eg_finalized_project_material_dataset_immutable BEFORE UPDATE OR DELETE ON public.estimate_dataset_versions
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_dataset_reference_immutable_guard();

REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_project_material_price_id_v4(bigint) FROM PUBLIC;
SQL);

        $this->routeFinalizerToV4();
        $this->routeValidatorToV4();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $hasV4 = DB::scalar(<<<'SQL'
SELECT EXISTS (SELECT 1 FROM public.estimate_generation_package_items
WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v4')
SQL);
            if ((bool) $hasV4) {
                throw new RuntimeException('estimate_generation.project_material_formula_rollback_blocked');
            }
            $this->restoreFinalizerV3();
            $this->restoreValidatorV3();
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_finalized_project_material_price_immutable ON public.estimate_resource_prices;
DROP FUNCTION IF EXISTS public.eg_project_material_price_reference_immutable_guard();
DROP TRIGGER IF EXISTS eg_finalized_project_material_dataset_immutable ON public.estimate_dataset_versions;
DROP FUNCTION IF EXISTS public.eg_project_material_dataset_reference_immutable_guard();
DROP TRIGGER IF EXISTS eg_project_price_input_append ON public.estimate_generation_package_item_project_price_inputs;
DROP TRIGGER IF EXISTS eg_project_material_rule_immutable ON public.estimate_generation_project_material_rules;
DROP FUNCTION IF EXISTS public.eg_project_material_rule_immutable_guard();
DROP FUNCTION IF EXISTS public.eg_expected_package_item_price_closed_v4(bigint);
DROP FUNCTION IF EXISTS public.eg_expected_package_item_price_v4(bigint);
DROP FUNCTION IF EXISTS public.eg_expected_project_material_price_id_v4(bigint);
SQL);
        }
        Schema::dropIfExists('estimate_generation_package_item_project_price_inputs');
        Schema::dropIfExists('estimate_generation_project_material_rules');
    }

    private function routeFinalizerToV4(): void
    {
        $definition = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/expected:=public\.eg_expected_package_item_price_closed_v3\(p_item_id\);/i',
            "IF EXISTS (SELECT 1 FROM public.estimate_generation_package_item_project_price_inputs WHERE package_item_id=p_item_id) THEN\n"
                ."    expected:=public.eg_expected_package_item_price_closed_v4(p_item_id);\n"
                .'  ELSE expected:=public.eg_expected_package_item_price_closed_v3(p_item_id); END IF;',
            'estimate_generation.project_material_finalize_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function routeValidatorToV4(): void
    {
        $definition = $this->definition('public.eg_package_item_price_validate()');
        $definition = $this->replaceOnce(
            $definition,
            "/IF\s+current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='project_resource:v3'\s+THEN/i",
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v4' THEN\n"
                ."    expected:=public.eg_expected_package_item_price_closed_v4(NEW.id);\n"
                ."  ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='project_resource:v3' THEN",
            'estimate_generation.project_material_validator_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function restoreFinalizerV3(): void
    {
        $definition = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/IF\s+EXISTS\s*\(SELECT\s+1\s+FROM\s+public\.estimate_generation_package_item_project_price_inputs\s+WHERE\s+package_item_id\s*=\s*p_item_id\)\s+THEN\s+expected:=public\.eg_expected_package_item_price_closed_v4\(p_item_id\);\s+ELSE\s+expected:=public\.eg_expected_package_item_price_closed_v3\(p_item_id\);\s+END\s+IF;/i',
            'expected:=public.eg_expected_package_item_price_closed_v3(p_item_id);',
            'estimate_generation.project_material_finalize_rollback_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function restoreValidatorV3(): void
    {
        $definition = $this->definition('public.eg_package_item_price_validate()');
        $definition = $this->replaceOnce(
            $definition,
            "/IF\s+current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v4'\s+THEN\s+expected:=public\.eg_expected_package_item_price_closed_v4\(NEW\.id\);\s+ELSIF/i",
            'IF',
            'estimate_generation.project_material_validator_rollback_contract_changed',
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

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        $now = now();

        return array_map(static fn (array $rule): array => [
            ...$rule,
            'fallback_name_markers' => json_encode($rule['fallback_name_markers'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'semantic_name_markers' => json_encode($rule['semantic_name_markers'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'electrical.main_cable', 'assumption_code' => 'residential_main_cable_vvgng_ls_3x6_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0154', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'electrical.power_lines', 'assumption_code' => 'residential_power_cable_vvgng_ls_3x2_5_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0152', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'lighting.lines', 'assumption_code' => 'residential_lighting_cable_vvgng_ls_3x1_5_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0151', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'electrical.panel', 'assumption_code' => 'residential_recessed_distribution_panel_24_modules', 'preferred_resource_code' => '20.4.04.02-0003', 'fallback_group_code' => '20.4.04.02', 'fallback_name_markers' => ['щит'], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'electrical.outlets', 'assumption_code' => 'residential_recessed_grounded_socket_with_shutter', 'preferred_resource_code' => '20.4.03.06-1036', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'electrical.switches', 'assumption_code' => 'residential_recessed_single_switch', 'preferred_resource_code' => '20.4.01.02-1023', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['catalog_version' => 'residential_project_material:v3', 'work_item_key' => 'lighting.fixtures', 'assumption_code' => 'residential_led_ceiling_luminaire_18w', 'preferred_resource_code' => '59.1.20.03-0798', 'fallback_group_code' => '59.1.20.03', 'fallback_name_markers' => ['светиль'], 'semantic_name_markers' => ['светиль', 'светодиод', 'потолоч'], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
        ]);
    }
};
