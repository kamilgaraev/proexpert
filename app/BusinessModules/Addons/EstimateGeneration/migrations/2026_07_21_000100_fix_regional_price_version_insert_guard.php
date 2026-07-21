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
CREATE OR REPLACE FUNCTION public.eg_used_pricing_source_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE source_id bigint:=CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END; used boolean:=false;
BEGIN
  IF TG_TABLE_NAME='estimate_regional_price_versions' THEN
    IF TG_OP='DELETE' THEN
      IF OLD.status IN ('active','superseded','rolled_back') THEN
        RAISE EXCEPTION 'estimate_generation.published_regional_price_version_is_immutable';
      END IF;
    ELSIF TG_OP='UPDATE' AND OLD.status IN ('active','superseded','rolled_back') THEN
      IF public.eg_regional_price_lifecycle_transition_allowed(OLD,NEW) THEN RETURN NEW; END IF;
    END IF;
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
REVOKE ALL ON FUNCTION public.eg_used_pricing_source_guard() FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
    }
};
