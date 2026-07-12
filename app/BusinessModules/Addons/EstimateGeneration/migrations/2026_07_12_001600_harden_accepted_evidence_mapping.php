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
        Schema::table('estimate_generation_unit_conversions', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
                $table->index(
                    ['package_id', 'logical_key', 'revision', 'id'],
                    'eg_package_item_latest_revision_idx',
                );
            });

            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE public.estimate_generation_pipeline_checkpoints
ADD CONSTRAINT eg_checkpoint_acceptance_identity_uq
UNIQUE (id, organization_id, project_id, session_id, output_version);

CREATE INDEX eg_package_item_latest_revision_idx
ON public.estimate_generation_package_items
(package_id, COALESCE(logical_key, key), revision DESC, id DESC);

ALTER TABLE public.estimate_generation_evidence
ADD CONSTRAINT eg_evidence_acceptance_identity_uq
UNIQUE (id, organization_id, project_id, session_id, fingerprint);

ALTER TABLE public.estimate_generation_accepted_evidence
ADD CONSTRAINT eg_accepted_evidence_checkpoint_fk
FOREIGN KEY (checkpoint_id, organization_id, project_id, session_id, output_version)
REFERENCES public.estimate_generation_pipeline_checkpoints
(id, organization_id, project_id, session_id, output_version) ON DELETE RESTRICT,
ADD CONSTRAINT eg_accepted_evidence_node_fk
FOREIGN KEY (evidence_id, organization_id, project_id, session_id, descriptor_fingerprint)
REFERENCES public.estimate_generation_evidence
(id, organization_id, project_id, session_id, fingerprint) ON DELETE RESTRICT;

CREATE FUNCTION public.eg_accepted_evidence_validate() RETURNS trigger
LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$
DECLARE checkpoint record; evidence record;
BEGIN
  IF TG_OP<>'INSERT' THEN
    RAISE EXCEPTION 'estimate_generation.accepted_evidence_is_immutable';
  END IF;
  SELECT c.* INTO checkpoint
  FROM public.estimate_generation_pipeline_checkpoints c
  WHERE c.id=NEW.checkpoint_id AND c.organization_id=NEW.organization_id
    AND c.project_id=NEW.project_id AND c.session_id=NEW.session_id;
  IF checkpoint.id IS NULL OR checkpoint.stage IS DISTINCT FROM 'plan_work_items'
    OR checkpoint.status IS DISTINCT FROM 'completed'
    OR checkpoint.output_version IS DISTINCT FROM NEW.output_version
  THEN RAISE EXCEPTION 'estimate_generation.accepted_evidence_checkpoint_invalid'; END IF;
  SELECT e.* INTO evidence
  FROM public.estimate_generation_evidence e
  WHERE e.id=NEW.evidence_id AND e.organization_id=NEW.organization_id
    AND e.project_id=NEW.project_id AND e.session_id=NEW.session_id
    AND e.fingerprint=NEW.descriptor_fingerprint;
  IF evidence.id IS NULL OR evidence.type IS DISTINCT FROM 'work_item'
    OR evidence.invalidated_at IS NOT NULL
    OR evidence.locator->>'item_key' !~ '^item:[a-f0-9]{64}$'
  THEN RAISE EXCEPTION 'estimate_generation.accepted_evidence_node_invalid'; END IF;
  RETURN NEW;
END; $$;

CREATE TRIGGER eg_accepted_evidence_validate_trg
BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_generation_accepted_evidence
FOR EACH ROW EXECUTE FUNCTION public.eg_accepted_evidence_validate();
REVOKE ALL ON FUNCTION public.eg_accepted_evidence_validate() FROM PUBLIC;

DROP TRIGGER IF EXISTS eg_unit_conversion_immutable ON public.estimate_generation_unit_conversions;
CREATE FUNCTION public.eg_unit_conversion_usage_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM public.estimate_generation_package_item_price_inputs i
    JOIN public.estimate_generation_package_items item ON item.id=i.package_item_id
    WHERE i.unit_conversion_id=OLD.id AND item.pricing_finalized_at IS NOT NULL
  ) THEN RAISE EXCEPTION 'estimate_generation.unit_conversion_in_use'; END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
CREATE TRIGGER eg_unit_conversion_usage_immutable
BEFORE UPDATE OR DELETE ON public.estimate_generation_unit_conversions
FOR EACH ROW EXECUTE FUNCTION public.eg_unit_conversion_usage_guard();
REVOKE ALL ON FUNCTION public.eg_unit_conversion_usage_guard() FROM PUBLIC;

CREATE OR REPLACE FUNCTION public.eg_pricing_provenance(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_build_object('schema_version','pricing_provenance:v1','resources',jsonb_agg(jsonb_build_object(
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
    'factor',c.factor::text,'version',c.version,'fingerprint',c.fingerprint)
    || CASE WHEN to_jsonb(c) ? 'is_active' THEN jsonb_build_object('is_active',to_jsonb(c)->'is_active') ELSE '{}'::jsonb END END
) ORDER BY i.ordinal))
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
REVOKE ALL ON FUNCTION public.eg_pricing_provenance(bigint) FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_package_items', function (Blueprint $table): void {
                $table->dropIndex('eg_package_item_latest_revision_idx');
            });
            Schema::table('estimate_generation_unit_conversions', fn (Blueprint $table): mixed => $table->dropColumn('is_active'));

            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_accepted_evidence_validate_trg ON public.estimate_generation_accepted_evidence;
DROP FUNCTION IF EXISTS public.eg_accepted_evidence_validate();
DROP INDEX IF EXISTS public.eg_package_item_latest_revision_idx;
DROP TRIGGER IF EXISTS eg_unit_conversion_usage_immutable ON public.estimate_generation_unit_conversions;
DROP FUNCTION IF EXISTS public.eg_unit_conversion_usage_guard();
CREATE TRIGGER eg_unit_conversion_immutable BEFORE UPDATE OR DELETE ON public.estimate_generation_unit_conversions
FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_catalog_immutable_guard();
ALTER TABLE public.estimate_generation_accepted_evidence DROP CONSTRAINT IF EXISTS eg_accepted_evidence_node_fk;
ALTER TABLE public.estimate_generation_accepted_evidence DROP CONSTRAINT IF EXISTS eg_accepted_evidence_checkpoint_fk;
ALTER TABLE public.estimate_generation_evidence DROP CONSTRAINT IF EXISTS eg_evidence_acceptance_identity_uq;
ALTER TABLE public.estimate_generation_pipeline_checkpoints DROP CONSTRAINT IF EXISTS eg_checkpoint_acceptance_identity_uq;
SQL);
        Schema::table('estimate_generation_unit_conversions', fn (Blueprint $table): mixed => $table->dropColumn('is_active'));
    }
};
