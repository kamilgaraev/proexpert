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
        Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
            $table->timestampTz('pricing_finalized_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_package_item_price_validate ON public.estimate_generation_package_items;
DROP FUNCTION IF EXISTS public.eg_package_item_price_validate();

CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price(p_item_id bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public AS $$
DECLARE item record; evidence record; expected_count bigint; actual_count bigint; computed numeric(30,2);
        canonical text; resources jsonb; captured text; snapshot jsonb;
BEGIN
  SELECT * INTO item FROM public.estimate_generation_package_items WHERE id=p_item_id;
  IF item.id IS NULL OR item.pricing_finalized_at IS NULL THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
  SELECT e.* INTO evidence FROM public.estimate_generation_evidence e
  JOIN public.estimate_generation_packages p ON p.id=item.package_id
  JOIN public.estimate_generation_sessions s ON s.id=p.session_id
  WHERE e.id=item.quantity_evidence_id AND e.organization_id=s.organization_id AND e.project_id=s.project_id
    AND e.session_id=s.id AND e.type='work_item' AND e.invalidated_at IS NULL
    AND e.fingerprint=item.quantity_evidence_fingerprint;
  IF evidence.id IS NULL OR (evidence.value->>'quantity')::numeric<=0
    OR evidence.locator->>'item_key' IS DISTINCT FROM 'item:'||encode(pg_catalog.sha256(pg_catalog.convert_to(item.logical_key,'UTF8')),'hex')
  THEN RAISE EXCEPTION 'estimate_generation.quantity_evidence_mismatch'; END IF;
  SELECT count(*) INTO expected_count FROM public.estimate_norm_resources WHERE estimate_norm_id=item.estimate_norm_id;
  SELECT count(*) INTO actual_count FROM public.estimate_generation_package_item_price_inputs WHERE package_item_id=item.id;
  IF expected_count=0 OR actual_count<>expected_count THEN RAISE EXCEPTION 'estimate_generation.norm_resource_set_mismatch'; END IF;
  IF EXISTS (
    SELECT 1 FROM public.estimate_generation_package_item_price_inputs i
    JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id
    JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
    LEFT JOIN public.estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id
    JOIN public.estimate_regional_price_versions v ON v.id=rp.regional_price_version_id
    WHERE i.package_item_id=item.id AND (nr.estimate_norm_id<>item.estimate_norm_id
      OR rp.resource_code IS DISTINCT FROM nr.resource_code OR rp.region_id<>item.region_id
      OR rp.price_zone_id<>item.price_zone_id OR rp.period_id<>item.period_id
      OR rp.regional_price_version_id<>item.regional_price_version_id OR v.status<>'active'
      OR rp.base_price IS NULL OR rp.base_price<=0
      OR (rp.unit IS DISTINCT FROM nr.unit AND (c.id IS NULL OR c.from_unit IS DISTINCT FROM nr.unit OR c.to_unit IS DISTINCT FROM rp.unit OR c.factor<=0))
      OR (rp.unit IS NOT DISTINCT FROM nr.unit AND c.id IS NOT NULL))
  ) THEN RAISE EXCEPTION 'estimate_generation.price_input_mismatch'; END IF;
  SELECT round(sum(nr.quantity*(evidence.value->>'quantity')::numeric*COALESCE(c.factor,1)*rp.base_price),2),
    string_agg(nr.id||':'||nr.estimate_norm_id||':'||nr.resource_code||':'||nr.resource_type||':'||nr.unit||':'||nr.quantity||':'||
      rp.id||':'||rp.regional_price_version_id||':'||rp.unit||':'||rp.base_price||':'||COALESCE(c.id::text,'identity')||':'||COALESCE(c.factor::text,'1'), '|' ORDER BY i.ordinal),
    jsonb_agg(jsonb_build_object('norm_resource_id',nr.id,'norm_id',nr.estimate_norm_id,'resource_code',nr.resource_code,
      'resource_type',nr.resource_type,'norm_unit',nr.unit,'norm_quantity',nr.quantity::text,'resource_price_id',rp.id,
      'price_unit',rp.unit,'base_price',rp.base_price::text,'unit_conversion_id',c.id,'conversion_factor',COALESCE(c.factor,1)::text) ORDER BY i.ordinal)
    INTO computed, canonical, resources
  FROM public.estimate_generation_package_item_price_inputs i
  JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id
  JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
  LEFT JOIN public.estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id WHERE i.package_item_id=item.id;
  IF computed IS NULL OR computed<=0 THEN RAISE EXCEPTION 'estimate_generation.price_inputs_missing'; END IF;
  canonical:=canonical||'|'||item.quantity_evidence_id||':'||item.quantity_evidence_fingerprint;
  captured:=to_char(item.pricing_finalized_at,'YYYY-MM-DD"T"HH24:MI:SSOF');
  snapshot:=jsonb_build_object('region_id',item.region_id,'zone_id',item.price_zone_id,'period_id',item.period_id,
    'version_id',item.regional_price_version_id,'source_type','regional_resource_aggregate',
    'source_reference','sha256:'||encode(pg_catalog.sha256(pg_catalog.convert_to(canonical,'UTF8')),'hex'),
    'base_amount',to_char(computed,'FM999999999999999999999999990.00'),
    'coefficients',jsonb_build_object('work_cost','0.00','quantity_evidence_id',item.quantity_evidence_id,
      'quantity_evidence_fingerprint',item.quantity_evidence_fingerprint,'resource_evidence',resources),
    'final_amount',to_char(computed,'FM999999999999999999999999990.00'),'currency','RUB','captured_at',captured);
  RETURN jsonb_build_object('quantity',(evidence.value->>'quantity')::numeric,'unit',evidence.value->>'unit',
    'unit_price',round(computed/(evidence.value->>'quantity')::numeric,6),'money',computed,'snapshot',snapshot);
END; $$;

CREATE OR REPLACE FUNCTION public.eg_finalize_package_item_price(p_item_id bigint) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public AS $$
DECLARE expected jsonb;
BEGIN
  PERFORM 1 FROM public.estimate_generation_package_items WHERE id=p_item_id AND price_snapshot IS NULL AND pricing_finalized_at IS NULL FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
  UPDATE public.estimate_generation_package_items SET pricing_finalized_at=clock_timestamp() WHERE id=p_item_id;
  expected:=public.eg_expected_package_item_price(p_item_id);
  UPDATE public.estimate_generation_package_items SET quantity=(expected->>'quantity')::numeric, unit=expected->>'unit',
    price_source='regional_catalog', unit_price=(expected->>'unit_price')::numeric, direct_cost=(expected->>'money')::numeric,
    overhead_cost=0, profit_cost=0, total_cost=(expected->>'money')::numeric, price_snapshot=expected->'snapshot' WHERE id=p_item_id;
END; $$;

CREATE FUNCTION public.eg_package_item_price_validate() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog, public AS $$
DECLARE expected jsonb; current_item record;
BEGIN
  SELECT * INTO current_item FROM public.estimate_generation_package_items WHERE id=NEW.id;
  IF current_item.price_snapshot IS NULL AND current_item.pricing_finalized_at IS NULL THEN RETURN NEW; END IF;
  IF current_item.price_snapshot IS NULL OR current_item.pricing_finalized_at IS NULL THEN RAISE EXCEPTION 'estimate_generation.priced_state_incomplete'; END IF;
  expected:=public.eg_expected_package_item_price(NEW.id);
  IF current_item.price_snapshot IS DISTINCT FROM expected->'snapshot' OR current_item.quantity IS DISTINCT FROM (expected->>'quantity')::numeric
    OR current_item.unit IS DISTINCT FROM expected->>'unit' OR current_item.unit_price IS DISTINCT FROM (expected->>'unit_price')::numeric
    OR current_item.direct_cost IS DISTINCT FROM (expected->>'money')::numeric OR current_item.total_cost IS DISTINCT FROM (expected->>'money')::numeric
    OR current_item.overhead_cost<>0 OR current_item.profit_cost<>0 OR current_item.price_source IS DISTINCT FROM 'regional_catalog'
  THEN RAISE EXCEPTION 'estimate_generation.database_built_price_mismatch'; END IF;
  RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER eg_package_item_price_validate AFTER INSERT OR UPDATE ON public.estimate_generation_package_items
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.eg_package_item_price_validate();

CREATE OR REPLACE FUNCTION public.eg_price_input_closed_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog, public AS $$ BEGIN
  IF EXISTS (SELECT 1 FROM public.estimate_generation_package_items WHERE id=COALESCE(NEW.package_item_id,OLD.package_item_id) AND pricing_finalized_at IS NOT NULL)
  THEN RAISE EXCEPTION 'estimate_generation.price_input_set_closed'; END IF;
  IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'estimate_generation.package_item_price_input_is_immutable'; END IF;
  RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_package_item_price_input_append ON public.estimate_generation_package_item_price_inputs;
CREATE TRIGGER eg_package_item_price_input_append BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_generation_package_item_price_inputs
FOR EACH ROW EXECUTE FUNCTION public.eg_price_input_closed_guard();
CREATE OR REPLACE FUNCTION public.eg_price_input_deferred_validate() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog, public AS $$ DECLARE item_id bigint; BEGIN
  item_id:=CASE WHEN TG_OP='DELETE' THEN OLD.package_item_id ELSE NEW.package_item_id END;
  IF EXISTS (SELECT 1 FROM public.estimate_generation_package_items WHERE id=item_id AND pricing_finalized_at IS NOT NULL)
  THEN PERFORM public.eg_expected_package_item_price(item_id); END IF;
  RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER eg_package_item_price_input_validate AFTER INSERT OR UPDATE OR DELETE ON public.estimate_generation_package_item_price_inputs
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.eg_price_input_deferred_validate();

CREATE OR REPLACE FUNCTION public.eg_pricing_reference_immutable_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog, public AS $$ DECLARE target_norm bigint; BEGIN
  IF TG_TABLE_NAME='estimate_norm_resources' THEN
    target_norm:=CASE WHEN TG_OP='DELETE' THEN OLD.estimate_norm_id ELSE NEW.estimate_norm_id END;
  END IF;
  IF TG_TABLE_NAME='estimate_norm_resources' AND EXISTS (SELECT 1 FROM public.estimate_generation_package_items p WHERE p.estimate_norm_id=target_norm AND p.pricing_finalized_at IS NOT NULL)
    THEN RAISE EXCEPTION 'estimate_generation.finalized_norm_resource_is_immutable'; END IF;
  IF TG_TABLE_NAME='estimate_regional_price_versions' THEN
    IF OLD.status='active' AND (TG_OP='DELETE' OR NEW.status IS DISTINCT FROM OLD.status OR ROW(NEW.region_id,NEW.price_zone_id,NEW.period_id,NEW.version_key) IS DISTINCT FROM ROW(OLD.region_id,OLD.price_zone_id,OLD.period_id,OLD.version_key))
    THEN RAISE EXCEPTION 'estimate_generation.regional_price_activation_is_irreversible'; END IF;
  END IF;
  IF TG_TABLE_NAME='estimate_dataset_versions' THEN
    IF EXISTS (SELECT 1 FROM public.estimate_resource_prices rp JOIN public.estimate_generation_package_item_price_inputs i ON i.resource_price_id=rp.id JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE rp.dataset_version_id=OLD.id AND p.pricing_finalized_at IS NOT NULL)
    THEN RAISE EXCEPTION 'estimate_generation.finalized_dataset_version_is_immutable'; END IF;
  END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE TRIGGER eg_finalized_norm_resource_immutable BEFORE UPDATE OR DELETE ON public.estimate_norm_resources FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_reference_immutable_guard();
CREATE TRIGGER eg_regional_price_activation_immutable BEFORE UPDATE OR DELETE ON public.estimate_regional_price_versions FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_reference_immutable_guard();
CREATE TRIGGER eg_finalized_dataset_version_immutable BEFORE UPDATE OR DELETE ON public.estimate_dataset_versions FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_reference_immutable_guard();

REVOKE ALL ON FUNCTION public.eg_expected_package_item_price(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_finalize_package_item_price(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.eg_finalize_package_item_price(bigint) TO CURRENT_USER;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_finalized_dataset_version_immutable ON public.estimate_dataset_versions;
DROP TRIGGER IF EXISTS eg_regional_price_activation_immutable ON public.estimate_regional_price_versions;
DROP TRIGGER IF EXISTS eg_finalized_norm_resource_immutable ON public.estimate_norm_resources;
DROP FUNCTION IF EXISTS public.eg_pricing_reference_immutable_guard();
DROP TRIGGER IF EXISTS eg_package_item_price_input_validate ON public.estimate_generation_package_item_price_inputs;
DROP FUNCTION IF EXISTS public.eg_price_input_deferred_validate();
DROP TRIGGER IF EXISTS eg_package_item_price_input_append ON public.estimate_generation_package_item_price_inputs;
DROP FUNCTION IF EXISTS public.eg_price_input_closed_guard();
DROP TRIGGER IF EXISTS eg_package_item_price_validate ON public.estimate_generation_package_items;
DROP FUNCTION IF EXISTS public.eg_package_item_price_validate();
DROP FUNCTION IF EXISTS public.eg_finalize_package_item_price(bigint);
DROP FUNCTION IF EXISTS public.eg_expected_package_item_price(bigint);
SQL);
        }
        Schema::table('estimate_generation_package_items', fn (Blueprint $table): mixed => $table->dropColumn('pricing_finalized_at'));
    }
};
