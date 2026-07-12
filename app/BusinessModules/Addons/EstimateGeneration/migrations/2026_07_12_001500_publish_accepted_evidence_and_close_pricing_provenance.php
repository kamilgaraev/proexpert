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
        Schema::create('estimate_generation_accepted_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('estimate_generation_pipeline_checkpoints')->restrictOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->char('output_version', 71);
            $table->char('descriptor_fingerprint', 64);
            $table->foreignId('evidence_id')->constrained('estimate_generation_evidence')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['checkpoint_id', 'descriptor_fingerprint'], 'eg_accepted_evidence_descriptor_uq');
            $table->index(['organization_id', 'project_id', 'session_id', 'descriptor_fingerprint'], 'eg_accepted_evidence_scope_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_pricing_provenance(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_build_object(
  'schema_version','pricing_provenance:v1',
  'resources',jsonb_agg(jsonb_build_object(
    'norm_resource_id',nr.id,'norm_id',nr.estimate_norm_id,'construction_resource_id',nr.construction_resource_id,
    'resource_code',nr.resource_code,'resource_name',nr.resource_name,'resource_type',nr.resource_type,'unit',nr.unit,
    'quantity',nr.quantity::text,'raw_payload_hash','sha256:'||encode(pg_catalog.sha256(pg_catalog.convert_to(COALESCE(nr.raw_payload::jsonb,'null'::jsonb)::text,'UTF8')),'hex'),
    'price_id',rp.id,'price_dataset_version_id',rp.dataset_version_id,'regional_price_version_id',rp.regional_price_version_id,
    'price_construction_resource_id',rp.construction_resource_id,'price_resource_code',rp.resource_code,
    'price_resource_name',rp.resource_name,'price_type',rp.price_type,'price_unit',rp.unit,'base_price',rp.base_price::text,
    'machine_salary_price',rp.machine_salary_price::text,'machine_price_without_salary',rp.machine_price_without_salary::text,
    'machine_labor_quantity',rp.machine_labor_quantity::text,'driver_code',rp.driver_code,'machinist_category',rp.machinist_category,
    'source_price_kind',rp.source_price_kind,
    'price_raw_payload_hash','sha256:'||encode(pg_catalog.sha256(pg_catalog.convert_to(COALESCE(rp.raw_payload::jsonb,'null'::jsonb)::text,'UTF8')),'hex'),
    'norm_dataset',jsonb_build_object('id',nd.id,'source_type',nd.source_type,'version_key',nd.version_key),
    'price_dataset',jsonb_build_object('id',pd.id,'source_type',pd.source_type,'version_key',pd.version_key),
    'regional_version',jsonb_build_object('id',rv.id,'source',rv.source,'version_key',rv.version_key),
    'conversion',CASE WHEN c.id IS NULL THEN NULL ELSE jsonb_build_object('id',c.id,'from_unit',c.from_unit,'to_unit',c.to_unit,
      'factor',c.factor::text,'version',c.version,'fingerprint',c.fingerprint) END
  ) ORDER BY i.ordinal)
)
FROM public.estimate_generation_package_item_price_inputs i
JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id
JOIN public.estimate_norms n ON n.id=nr.estimate_norm_id
JOIN public.estimate_norm_collections nc ON nc.id=n.collection_id
JOIN public.estimate_dataset_versions nd ON nd.id=nc.dataset_version_id
JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id
JOIN public.estimate_dataset_versions pd ON pd.id=rp.dataset_version_id
JOIN public.estimate_regional_price_versions rv ON rv.id=rp.regional_price_version_id
LEFT JOIN public.estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id
WHERE i.package_item_id=p_item_id;
$$;

CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;

CREATE OR REPLACE FUNCTION public.eg_finalize_package_item_price(p_item_id bigint) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE expected jsonb;
BEGIN
  PERFORM 1 FROM public.estimate_generation_package_items WHERE id=p_item_id AND price_snapshot IS NULL AND pricing_finalized_at IS NULL FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
  UPDATE public.estimate_generation_package_items SET pricing_finalized_at=clock_timestamp() WHERE id=p_item_id;
  expected:=public.eg_expected_package_item_price_closed(p_item_id);
  UPDATE public.estimate_generation_package_items SET quantity=(expected->>'quantity')::numeric,unit=expected->>'unit',price_source='regional_catalog',
    unit_price=(expected->>'unit_price')::numeric,direct_cost=(expected->>'money')::numeric,overhead_cost=0,profit_cost=0,
    total_cost=(expected->>'money')::numeric,price_snapshot=expected->'snapshot' WHERE id=p_item_id;
END; $$;

CREATE OR REPLACE FUNCTION public.eg_package_item_price_validate() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ DECLARE expected jsonb; current_item record; BEGIN
  SELECT * INTO current_item FROM public.estimate_generation_package_items WHERE id=NEW.id;
  IF current_item.price_snapshot IS NULL AND current_item.pricing_finalized_at IS NULL THEN RETURN NEW; END IF;
  IF current_item.price_snapshot IS NULL OR current_item.pricing_finalized_at IS NULL THEN RAISE EXCEPTION 'estimate_generation.priced_state_incomplete'; END IF;
  expected:=public.eg_expected_package_item_price_closed(NEW.id);
  IF current_item.price_snapshot IS DISTINCT FROM expected->'snapshot' OR current_item.quantity IS DISTINCT FROM (expected->>'quantity')::numeric
    OR current_item.unit IS DISTINCT FROM expected->>'unit' OR current_item.unit_price IS DISTINCT FROM (expected->>'unit_price')::numeric
    OR current_item.direct_cost IS DISTINCT FROM (expected->>'money')::numeric OR current_item.total_cost IS DISTINCT FROM (expected->>'money')::numeric
    OR current_item.overhead_cost<>0 OR current_item.profit_cost<>0 OR current_item.price_source IS DISTINCT FROM 'regional_catalog'
  THEN RAISE EXCEPTION 'estimate_generation.database_built_price_mismatch'; END IF;
  RETURN NEW;
END; $$;

DROP TRIGGER IF EXISTS eg_finalized_norm_resource_immutable ON public.estimate_norm_resources;
CREATE TRIGGER eg_finalized_norm_resource_immutable BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_norm_resources
FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_reference_immutable_guard();
CREATE OR REPLACE FUNCTION public.eg_work_item_decimal_prepare() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ BEGIN
  IF NEW.type='work_item' AND NEW.value ? 'quantity' THEN
    IF jsonb_typeof(NEW.value->'quantity')<>'string' OR NEW.value->>'quantity' !~ '^(0|[1-9][0-9]*)(\.[0-9]+)?$'
      OR length(NEW.value->>'quantity')>64 OR (NEW.value->>'quantity')::numeric NOT BETWEEN 0 AND 1000000000000
    THEN RAISE EXCEPTION 'estimate_generation.evidence_decimal_invalid'; END IF;
    PERFORM pg_catalog.set_config('eg.quantity_string_fingerprint',NEW.fingerprint,true);
    NEW.value:=jsonb_set(NEW.value,'{quantity}',to_jsonb((NEW.value->>'quantity')::numeric));
  END IF;
  RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION public.eg_work_item_decimal_restore() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ DECLARE canonical text; BEGIN
  IF NEW.type='work_item' AND NEW.value ? 'quantity' THEN
    IF pg_catalog.current_setting('eg.quantity_string_fingerprint',true) IS DISTINCT FROM NEW.fingerprint
    THEN RAISE EXCEPTION 'estimate_generation.evidence_decimal_must_be_string'; END IF;
    canonical:=NEW.value->>'quantity';
    NEW.value:=jsonb_set(NEW.value,'{quantity}',to_jsonb(canonical));
  END IF;
  RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_evidence_decimal_00_prepare ON public.estimate_generation_evidence;
DROP TRIGGER IF EXISTS eg_evidence_j_decimal_prepare ON public.estimate_generation_evidence;
DROP TRIGGER IF EXISTS eg_evidence_decimal_zz_restore ON public.estimate_generation_evidence;
DROP TRIGGER IF EXISTS eg_evidence_zz_decimal_restore ON public.estimate_generation_evidence;
CREATE TRIGGER eg_evidence_j_decimal_prepare BEFORE INSERT OR UPDATE ON public.estimate_generation_evidence
FOR EACH ROW EXECUTE FUNCTION public.eg_work_item_decimal_prepare();
CREATE TRIGGER eg_evidence_zz_decimal_restore BEFORE INSERT OR UPDATE ON public.estimate_generation_evidence
FOR EACH ROW EXECUTE FUNCTION public.eg_work_item_decimal_restore();
REVOKE ALL ON FUNCTION public.eg_pricing_provenance(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed(bigint) FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_evidence_zz_decimal_restore ON public.estimate_generation_evidence;
DROP TRIGGER IF EXISTS eg_evidence_j_decimal_prepare ON public.estimate_generation_evidence;
DROP TRIGGER IF EXISTS eg_evidence_decimal_00_prepare ON public.estimate_generation_evidence;
DROP FUNCTION IF EXISTS public.eg_work_item_decimal_restore();
DROP FUNCTION IF EXISTS public.eg_work_item_decimal_prepare();
DROP TRIGGER IF EXISTS eg_finalized_norm_resource_immutable ON public.estimate_norm_resources;
CREATE TRIGGER eg_finalized_norm_resource_immutable BEFORE UPDATE OR DELETE ON public.estimate_norm_resources
FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_reference_immutable_guard();

CREATE OR REPLACE FUNCTION public.eg_finalize_package_item_price(p_item_id bigint) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE expected jsonb;
BEGIN
  PERFORM 1 FROM public.estimate_generation_package_items WHERE id=p_item_id AND price_snapshot IS NULL AND pricing_finalized_at IS NULL FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'estimate_generation.price_finalize_state_invalid'; END IF;
  UPDATE public.estimate_generation_package_items SET pricing_finalized_at=clock_timestamp() WHERE id=p_item_id;
  expected:=public.eg_expected_package_item_price(p_item_id);
  UPDATE public.estimate_generation_package_items SET quantity=(expected->>'quantity')::numeric,unit=expected->>'unit',price_source='regional_catalog',
    unit_price=(expected->>'unit_price')::numeric,direct_cost=(expected->>'money')::numeric,overhead_cost=0,profit_cost=0,
    total_cost=(expected->>'money')::numeric,price_snapshot=expected->'snapshot' WHERE id=p_item_id;
END; $$;

CREATE OR REPLACE FUNCTION public.eg_package_item_price_validate() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ DECLARE expected jsonb; current_item record; BEGIN
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

DROP FUNCTION IF EXISTS public.eg_expected_package_item_price_closed(bigint);
DROP FUNCTION IF EXISTS public.eg_pricing_provenance(bigint);
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_finalize_package_item_price(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.eg_finalize_package_item_price(bigint) TO CURRENT_USER;
SQL);
        }
        Schema::dropIfExists('estimate_generation_accepted_evidence');
    }
};
