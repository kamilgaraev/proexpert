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
CREATE OR REPLACE FUNCTION public.eg_regional_price_retention_json_version_ids(payload jsonb, generic_version boolean)
RETURNS text[] LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,public AS $$
SELECT COALESCE(array_agg(reference_id ORDER BY reference_id),ARRAY[]::text[])
FROM public.eg_regional_price_retention_json_references(payload,COALESCE(generic_version,false))
WHERE reference_kind='version';
$$;
SQL);
        (new EstimateGenerationPriceLookupIndexRuntime)->ensureRetentionVersionReferences();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_regional_price_retention_references_any(version_ids bigint[], price_ids bigint[])
RETURNS boolean LANGUAGE plpgsql VOLATILE SET search_path=pg_catalog,public AS $$
DECLARE evidence record; field record; matched boolean; projection text; candidate_filter text; document_projection text; version_filter text; version_candidates text;
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
    SELECT string_agg(format('public.eg_regional_price_retention_json_version_ids(t.%I::jsonb,%L::boolean) && ARRAY(SELECT id::text FROM unnest($1::bigint[]) id)',key,value='snapshot'),' OR ')
      INTO version_filter FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    SELECT string_agg(format('cardinality(public.eg_regional_price_retention_json_version_ids(t.%I::jsonb,%L::boolean))>0',key,value='snapshot'),' OR ')
      INTO version_candidates FROM jsonb_each_text(evidence.fields) WHERE value IN ('json','snapshot');
    IF projection IS NOT NULL THEN
      EXECUTE format('WITH price_scope AS MATERIALIZED (
          SELECT COALESCE(cardinality($2::bigint[]),0)>0 OR EXISTS(
            SELECT 1 FROM public.estimate_resource_prices WHERE regional_price_version_id=ANY($1::bigint[])) AS has_prices
        ), candidate_rows AS MATERIALIZED (
          SELECT %s FROM public.%I t WHERE EXISTS(SELECT 1 FROM price_scope WHERE has_prices) AND (%s)
        ) SELECT EXISTS(
          SELECT 1 FROM price_scope WHERE NOT has_prices AND EXISTS(
            SELECT 1 FROM public.%I t WHERE (%s) AND (%s))
          UNION ALL
          SELECT 1 FROM price_scope WHERE has_prices AND EXISTS(SELECT 1 FROM candidate_rows t
        CROSS JOIN LATERAL (VALUES %s) payload(document,generic_version)
        CROSS JOIN LATERAL public.eg_regional_price_retention_json_references(payload.document,payload.generic_version) r
        WHERE ((r.reference_kind=''version'' AND r.reference_id IN(SELECT id::text FROM unnest($1::bigint[]) id))
          OR (r.reference_kind=''price'' AND (r.reference_id IN(SELECT id::text FROM unnest($2::bigint[]) id)
            OR EXISTS(SELECT 1 FROM public.estimate_resource_prices price
              WHERE price.id=CASE WHEN length(r.reference_id)<=19 AND r.reference_id::numeric<=9223372036854775807
                THEN r.reference_id::bigint END AND price.regional_price_version_id=ANY($1)))))))',document_projection,evidence.table_name,candidate_filter,evidence.table_name,version_candidates,version_filter,projection)
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
        (new EstimateGenerationPriceLookupIndexRuntime)->dropRetentionVersionReferences();
        DB::statement('DROP FUNCTION IF EXISTS public.eg_regional_price_retention_json_version_ids(jsonb,boolean)');
    }
};
