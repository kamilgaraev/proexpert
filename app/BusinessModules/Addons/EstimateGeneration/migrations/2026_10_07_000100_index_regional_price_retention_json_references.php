<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationPriceLookupIndexRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_regional_price_retention_json_may_reference(payload jsonb)
RETURNS boolean LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,public AS $$
SELECT payload::text ~ '"(price_id|resource_price_id|regional_price_version_id|estimate_regional_price_version_id|candidate_resource_price_ids|version_id)"[[:space:]]*:|estimate_resource_prices:';
$$;
SQL);
        (new EstimateGenerationPriceLookupIndexRuntime)->ensureRetentionReferenceCandidates();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_regional_price_retention_references_any(version_ids bigint[], price_ids bigint[])
RETURNS boolean LANGUAGE plpgsql VOLATILE SET search_path=pg_catalog,public AS $$
DECLARE evidence record; field record; matched boolean; projection text; candidate_filter text; document_projection text;
BEGIN
  FOR evidence IN SELECT * FROM public.eg_regional_price_retention_reference_fields() LOOP
    FOR field IN SELECT key,value FROM jsonb_each_text(evidence.fields) WHERE value IN ('price','version') LOOP
      IF field.value='price' THEN
        EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I evidence WHERE evidence.%I = ANY($2)
          OR EXISTS(SELECT 1 FROM public.estimate_resource_prices price
            WHERE price.id=evidence.%I AND price.regional_price_version_id=ANY($1)))',evidence.table_name,field.key,field.key)
          INTO matched USING version_ids,price_ids;
      ELSE
        EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I WHERE %I = ANY($1))',evidence.table_name,field.key)
          INTO matched USING version_ids;
      END IF;
      IF matched THEN RETURN true; END IF;
    END LOOP;
    SELECT string_agg(format('(t.%I::jsonb,%L::boolean)',key,value='snapshot'),',')
      INTO projection FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    SELECT string_agg(format('public.eg_regional_price_retention_json_may_reference(t.%I::jsonb)',key),' OR ')
      INTO candidate_filter FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    SELECT string_agg(format('t.%I',key),',')
      INTO document_projection FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    IF projection IS NOT NULL THEN
      EXECUTE format('WITH candidate_rows AS MATERIALIZED (SELECT %s FROM public.%I t WHERE %s)
        SELECT EXISTS(SELECT 1 FROM candidate_rows t
        CROSS JOIN LATERAL (VALUES %s) payload(document,generic_version)
        CROSS JOIN LATERAL public.eg_regional_price_retention_json_references(payload.document,payload.generic_version) r
        WHERE ((r.reference_kind=''version'' AND r.reference_id IN(SELECT id::text FROM unnest($1::bigint[]) id))
          OR (r.reference_kind=''price'' AND (r.reference_id IN(SELECT id::text FROM unnest($2::bigint[]) id)
            OR EXISTS(SELECT 1 FROM public.estimate_resource_prices price
              WHERE price.id=CASE WHEN length(r.reference_id)<=19 AND r.reference_id::numeric<=9223372036854775807
                THEN r.reference_id::bigint END AND price.regional_price_version_id=ANY($1))))))',document_projection,evidence.table_name,candidate_filter,projection)
        INTO matched USING version_ids,price_ids;
      IF matched THEN RETURN true; END IF;
    END IF;
  END LOOP;
  RETURN false;
END; $$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_regional_price_retention_references_any(version_ids bigint[], price_ids bigint[])
RETURNS boolean LANGUAGE plpgsql VOLATILE SET search_path=pg_catalog,public AS $$
DECLARE evidence record; field record; matched boolean; projection text;
BEGIN
  FOR evidence IN SELECT * FROM public.eg_regional_price_retention_reference_fields() LOOP
    FOR field IN SELECT key,value FROM jsonb_each_text(evidence.fields) WHERE value IN ('price','version') LOOP
      IF field.value='price' THEN
        EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I evidence WHERE evidence.%I = ANY($2)
          OR EXISTS(SELECT 1 FROM public.estimate_resource_prices price
            WHERE price.id=evidence.%I AND price.regional_price_version_id=ANY($1)))',evidence.table_name,field.key,field.key)
          INTO matched USING version_ids,price_ids;
      ELSE
        EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I WHERE %I = ANY($1))',evidence.table_name,field.key)
          INTO matched USING version_ids;
      END IF;
      IF matched THEN RETURN true; END IF;
    END LOOP;
    SELECT string_agg(format('(t.%I::jsonb,%L::boolean)',key,value='snapshot'),',')
      INTO projection FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    IF projection IS NOT NULL THEN
      EXECUTE format('SELECT EXISTS(SELECT 1 FROM public.%I t
        CROSS JOIN LATERAL (VALUES %s) payload(document,generic_version)
        CROSS JOIN LATERAL public.eg_regional_price_retention_json_references(payload.document,payload.generic_version) r
        WHERE (r.reference_kind=''version'' AND r.reference_id IN(SELECT id::text FROM unnest($1::bigint[]) id))
          OR (r.reference_kind=''price'' AND (r.reference_id IN(SELECT id::text FROM unnest($2::bigint[]) id)
            OR EXISTS(SELECT 1 FROM public.estimate_resource_prices price
              WHERE price.id=CASE WHEN length(r.reference_id)<=19 AND r.reference_id::numeric<=9223372036854775807
                THEN r.reference_id::bigint END AND price.regional_price_version_id=ANY($1)))))',evidence.table_name,projection)
        INTO matched USING version_ids,price_ids;
      IF matched THEN RETURN true; END IF;
    END IF;
  END LOOP;
  RETURN false;
END; $$;
SQL);
        (new EstimateGenerationPriceLookupIndexRuntime)->dropRetentionReferenceCandidates();
        DB::statement('DROP FUNCTION IF EXISTS public.eg_regional_price_retention_json_may_reference(jsonb)');
    }
};
