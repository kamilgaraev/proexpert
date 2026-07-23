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
CREATE OR REPLACE FUNCTION public.eg_price_input_deferred_validate() RETURNS trigger LANGUAGE plpgsql
SET search_path = pg_catalog, public AS $$
DECLARE
  item_id bigint;
  current_item record;
BEGIN
  item_id:=CASE WHEN TG_OP='DELETE' THEN OLD.package_item_id ELSE NEW.package_item_id END;
  SELECT * INTO current_item FROM public.estimate_generation_package_items WHERE id=item_id;
  IF current_item.pricing_finalized_at IS NOT NULL THEN
    IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v8' THEN
      PERFORM public.eg_expected_package_item_price_closed_v8(item_id);
    ELSE
      PERFORM public.eg_expected_package_item_price(item_id);
    END IF;
  END IF;
  RETURN NULL;
END; $$;
SQL);
    }

    public function down(): void
    {
    }
};
