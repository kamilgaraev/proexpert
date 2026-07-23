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
CREATE OR REPLACE FUNCTION public.eg_regional_price_lifecycle_transition_allowed(
  OLD public.estimate_regional_price_versions,
  NEW public.estimate_regional_price_versions
) RETURNS boolean LANGUAGE plpgsql STABLE SET search_path=pg_catalog,public AS $$
BEGIN
  IF OLD.status='active' AND NEW.status='superseded' AND NEW.superseded_at IS NOT NULL
       AND NEW.activated_at IS NOT DISTINCT FROM OLD.activated_at
       AND NEW.rolled_back_at IS NOT DISTINCT FROM OLD.rolled_back_at
    OR OLD.status='active' AND NEW.status='rolled_back' AND NEW.rolled_back_at IS NOT NULL
       AND NEW.activated_at IS NOT DISTINCT FROM OLD.activated_at
       AND NEW.superseded_at IS NOT DISTINCT FROM OLD.superseded_at
    OR OLD.status IN ('superseded','rolled_back') AND NEW.status='active' AND NEW.activated_at IS NOT NULL
       AND NEW.superseded_at IS NULL AND NEW.rolled_back_at IS NULL
  THEN
    IF (to_jsonb(NEW) - ARRAY['status','activated_at','superseded_at','rolled_back_at','updated_at'])
      IS NOT DISTINCT FROM
      (to_jsonb(OLD) - ARRAY['status','activated_at','superseded_at','rolled_back_at','updated_at'])
    THEN RETURN true; END IF;
    RAISE EXCEPTION 'estimate_generation.published_regional_price_version_is_immutable';
  END IF;
  RAISE EXCEPTION 'estimate_generation.regional_price_lifecycle_transition_invalid';
END; $$;

CREATE OR REPLACE FUNCTION public.eg_pricing_catalog_immutable_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$
DECLARE catalog_status text;
BEGIN
  IF TG_TABLE_NAME='estimate_resource_prices' THEN
    SELECT status INTO catalog_status FROM public.estimate_regional_price_versions
    WHERE id IN (
      CASE WHEN TG_OP IN ('UPDATE','DELETE') THEN OLD.regional_price_version_id END,
      CASE WHEN TG_OP IN ('UPDATE','INSERT') THEN NEW.regional_price_version_id END
    ) AND status IN ('active','superseded','rolled_back') LIMIT 1;
    IF catalog_status IS NOT NULL
    THEN RAISE EXCEPTION 'estimate_generation.published_pricing_catalog_is_immutable'; END IF;
  END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
DROP TRIGGER IF EXISTS eg_active_resource_price_immutable ON public.estimate_resource_prices;
CREATE TRIGGER eg_active_resource_price_immutable BEFORE INSERT OR UPDATE OR DELETE ON public.estimate_resource_prices
FOR EACH ROW EXECUTE FUNCTION public.eg_pricing_catalog_immutable_guard();

CREATE OR REPLACE FUNCTION public.eg_used_pricing_source_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE source_id bigint:=CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END; used boolean:=false;
BEGIN
  IF TG_TABLE_NAME='estimate_regional_price_versions' AND TG_OP='DELETE'
    AND OLD.status IN ('active','superseded','rolled_back')
  THEN RAISE EXCEPTION 'estimate_generation.published_regional_price_version_is_immutable'; END IF;
  IF TG_TABLE_NAME='estimate_regional_price_versions' AND TG_OP='UPDATE'
    AND OLD.status IN ('active','superseded','rolled_back') THEN
    IF public.eg_regional_price_lifecycle_transition_allowed(OLD,NEW) THEN RETURN NEW; END IF;
  END IF;
  IF TG_TABLE_NAME='estimate_norm_resources' THEN
    IF TG_OP='INSERT' THEN
      SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_items p WHERE p.estimate_norm_id=NEW.estimate_norm_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
    ELSE
      SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE i.norm_resource_id=source_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
    END IF;
  ELSIF TG_TABLE_NAME='estimate_norms' THEN
    SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE nr.estimate_norm_id=source_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
  ELSIF TG_TABLE_NAME='estimate_norm_collections' THEN
    SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id JOIN public.estimate_norms n ON n.id=nr.estimate_norm_id JOIN public.estimate_norm_collections nc ON nc.id=n.collection_id JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE n.collection_id=source_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
  ELSIF TG_TABLE_NAME='estimate_dataset_versions' THEN
    SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_norm_resources nr ON nr.id=i.norm_resource_id JOIN public.estimate_norms n ON n.id=nr.estimate_norm_id JOIN public.estimate_norm_collections nc ON nc.id=n.collection_id JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE (nc.dataset_version_id=source_id OR rp.dataset_version_id=source_id) AND p.pricing_finalized_at IS NOT NULL) INTO used;
  ELSIF TG_TABLE_NAME='estimate_resource_prices' THEN
    SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE i.resource_price_id=source_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
  ELSIF TG_TABLE_NAME='estimate_regional_price_versions' THEN
    SELECT EXISTS(SELECT 1 FROM public.estimate_generation_package_item_price_inputs i JOIN public.estimate_resource_prices rp ON rp.id=i.resource_price_id JOIN public.estimate_generation_package_items p ON p.id=i.package_item_id WHERE rp.regional_price_version_id=source_id AND p.pricing_finalized_at IS NOT NULL) INTO used;
  END IF;
  IF used THEN RAISE EXCEPTION 'estimate_generation.used_pricing_source_is_immutable'; END IF;
  RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END; $$;
REVOKE ALL ON FUNCTION public.eg_regional_price_lifecycle_transition_allowed(public.estimate_regional_price_versions,public.estimate_regional_price_versions) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_used_pricing_source_guard() FROM PUBLIC;
SQL);

        $this->replaceActiveCatalogStatus('public.eg_expected_project_material_price_id_v4(bigint)');
        $this->replaceActiveCatalogStatus('public.eg_expected_package_item_price_v4(bigint)');
        $this->replaceActiveCatalogStatus('public.eg_project_material_price_mismatch_code_v4(bigint)');
        $this->replaceInactiveCatalogStatus('public.eg_expected_package_item_price(bigint)');
        $this->removeLifecycleFieldsFromProvenance();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        throw new \RuntimeException('estimate_generation.published_regional_price_lifecycle_rollback_blocked');
    }

    private function replaceActiveCatalogStatus(string $signature): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        $before = "rv.status='active'";
        $after = "rv.status IN ('active','superseded','rolled_back')";

        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new \RuntimeException('estimate_generation.published_regional_price_lifecycle_contract_changed');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }

    private function removeLifecycleFieldsFromProvenance(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('public.eg_pricing_provenance(bigint)'::regprocedure)");
        $before = "'version_key',rv.version_key,'status',rv.status,'files_count',rv.files_count,'rows_read',rv.rows_read,'rows_imported',rv.rows_imported,\n    'errors_count',rv.errors_count,'activated_at',rv.activated_at,'superseded_at',rv.superseded_at,'rolled_back_at',rv.rolled_back_at,\n    'metadata_hash',public.eg_bounded_pricing_json_hash(rv.metadata::jsonb)";
        $after = "'version_key',rv.version_key,'files_count',rv.files_count,'rows_read',rv.rows_read,'rows_imported',rv.rows_imported,\n    'errors_count',rv.errors_count,'metadata_hash',public.eg_bounded_pricing_json_hash(rv.metadata::jsonb)";

        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new \RuntimeException('estimate_generation.published_regional_price_provenance_contract_changed');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }

    private function replaceInactiveCatalogStatus(string $signature): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        $before = "v.status<>'active'";
        $after = "v.status NOT IN ('active','superseded','rolled_back')";

        if (! is_string($definition) || substr_count($definition, $before) !== 1) {
            throw new \RuntimeException('estimate_generation.published_regional_price_lifecycle_contract_changed');
        }

        DB::unprepared(str_replace($before, $after, $definition));
    }
};
