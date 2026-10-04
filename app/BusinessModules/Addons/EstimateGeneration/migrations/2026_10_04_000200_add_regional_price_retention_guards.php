<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const GUARDS = [
        'eg_pricing_catalog_immutable_guard',
        'eg_used_pricing_source_guard',
        'eg_project_material_price_reference_immutable_guard',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.eg_regional_price_retention_json_references(payload jsonb, generic_version boolean DEFAULT false)
RETURNS TABLE(reference_kind text, reference_id text) LANGUAGE plpgsql IMMUTABLE
SET search_path=pg_catalog,public AS $$
BEGIN
  IF payload IS NULL OR NOT (payload::text ~ '"(price_id|resource_price_id|regional_price_version_id|estimate_regional_price_version_id|candidate_resource_price_ids|version_id)"[[:space:]]*:|estimate_resource_prices:') THEN
    RETURN;
  END IF;
  RETURN QUERY
WITH fields AS (
  SELECT field.key,
    CASE WHEN field.key<>'candidate_resource_price_ids' THEN field.value #>> '{}' END AS scalar_value,
    CASE WHEN field.key='candidate_resource_price_ids' THEN field.value END AS candidate_values
  FROM jsonb_path_query(COALESCE(payload,'null'::jsonb), 'strict $.** ? (@.type() == "object")') object(value)
  CROSS JOIN LATERAL jsonb_each(object.value) field
  WHERE (field.key IN ('price_id','resource_price_id','regional_price_version_id','estimate_regional_price_version_id')
      AND jsonb_typeof(field.value) IN ('number','string') AND field.value #>> '{}' ~ '^[1-9][0-9]*$')
    OR (field.key='version_id' AND (generic_version OR (jsonb_exists_all(object.value,ARRAY['region_id','period_id'])
      AND jsonb_exists_any(object.value,ARRAY['zone_id','price_zone_id']))) AND jsonb_typeof(field.value) IN ('number','string')
      AND field.value #>> '{}' ~ '^[1-9][0-9]*$')
    OR (field.key='source_reference' AND jsonb_typeof(field.value)='string'
      AND field.value #>> '{}' ~ '^estimate_resource_prices:[1-9][0-9]*$')
    OR (field.key='candidate_resource_price_ids' AND jsonb_typeof(field.value)='array')
)
SELECT DISTINCT references_found.reference_kind, references_found.reference_id FROM (
  SELECT CASE WHEN key IN ('price_id','resource_price_id') THEN 'price' ELSE 'version' END AS reference_kind,
    scalar_value AS reference_id
  FROM fields WHERE key IN ('price_id','resource_price_id','regional_price_version_id','estimate_regional_price_version_id','version_id')
  UNION ALL
  SELECT 'price',substring(scalar_value FROM '^estimate_resource_prices:([1-9][0-9]*)$')
  FROM fields WHERE key='source_reference'
  UNION ALL
  SELECT 'price',candidate.value #>> '{}'
  FROM fields CROSS JOIN LATERAL jsonb_array_elements(COALESCE(candidate_values,'[]'::jsonb)) candidate(value)
  WHERE key='candidate_resource_price_ids' AND candidate.value #>> '{}' ~ '^[1-9][0-9]*$'
) references_found;
END;
$$;

CREATE FUNCTION public.eg_regional_price_retention_row_references(payload jsonb, fields jsonb)
RETURNS TABLE(reference_kind text, reference_id text) LANGUAGE sql IMMUTABLE
SET search_path=pg_catalog,public AS $$
SELECT field.value, payload->>field.key FROM jsonb_each_text(fields) field
WHERE field.value IN ('price','version') AND payload->>field.key ~ '^[1-9][0-9]*$'
UNION
SELECT parsed.reference_kind, parsed.reference_id
FROM jsonb_each_text(fields) field
CROSS JOIN LATERAL public.eg_regional_price_retention_json_references(payload->field.key,field.value='snapshot') parsed
WHERE field.value IN ('json','snapshot');
$$;

CREATE FUNCTION public.eg_regional_price_retention_reference_fields()
RETURNS TABLE(table_name text, fields jsonb) LANGUAGE sql STABLE
SET search_path=pg_catalog,public AS $$
WITH foreign_fields AS (
  SELECT source.relname::text AS table_name, attribute.attname::text AS column_name,
    CASE WHEN target.relname='estimate_resource_prices' THEN 'price' ELSE 'version' END AS kind
  FROM pg_constraint constraint_row
  JOIN pg_class source ON source.oid=constraint_row.conrelid
  JOIN pg_namespace namespace ON namespace.oid=source.relnamespace AND namespace.nspname='public'
  JOIN pg_class target ON target.oid=constraint_row.confrelid
  JOIN pg_namespace target_namespace ON target_namespace.oid=target.relnamespace AND target_namespace.nspname='public'
  CROSS JOIN LATERAL unnest(constraint_row.conkey) source_key(attnum)
  JOIN pg_attribute attribute ON attribute.attrelid=source.oid AND attribute.attnum=source_key.attnum
  WHERE constraint_row.contype='f' AND cardinality(constraint_row.conkey)=1
    AND target.relname IN ('estimate_resource_prices','estimate_regional_price_versions')
    AND source.relname<>'estimate_resource_prices'
), declared_fields AS (
  SELECT relation.relname::text AS table_name, attribute.attname::text AS column_name,
    CASE WHEN attribute.attname IN ('resource_price_id') THEN 'price'
      WHEN attribute.attname IN ('regional_price_version_id','estimate_regional_price_version_id','active_version_id','previous_version_id') THEN 'version'
      WHEN attribute.attname IN ('price_snapshot','regional_price_snapshot')
        AND relation.relname NOT IN ('estimate_generation_ai_usage','estimate_generation_vision_physical_attempts') THEN 'snapshot'
      ELSE 'json' END AS kind
  FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace AND namespace.nspname='public'
  JOIN pg_attribute attribute ON attribute.attrelid=relation.oid AND attribute.attnum>0 AND NOT attribute.attisdropped
  WHERE relation.relkind IN ('r','p') AND relation.relname IN (
    'estimates','estimate_items','estimate_regional_price_activations','estimate_generation_package_items',
    'estimate_generation_package_item_price_inputs','estimate_generation_package_item_project_price_inputs',
    'estimate_generation_ai_usage','estimate_generation_vision_physical_attempts')
  AND (attribute.attname IN ('regional_price_version_id','estimate_regional_price_version_id','active_version_id','previous_version_id','resource_price_id')
    OR (attribute.atttypid IN ('json'::regtype,'jsonb'::regtype)
      AND attribute.attname IN ('price_snapshot','regional_price_snapshot','metadata','resources','resource_calculation','custom_resources','selection')))
), all_fields AS (
  SELECT * FROM foreign_fields UNION SELECT * FROM declared_fields
)
SELECT table_name,jsonb_object_agg(column_name,kind) FROM all_fields GROUP BY table_name ORDER BY table_name;
$$;

CREATE FUNCTION public.eg_regional_price_retention_lock_evidence() RETURNS void LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$
DECLARE evidence record;
BEGIN
  PERFORM pg_advisory_xact_lock(1936028786,170200);
  FOR evidence IN SELECT table_name FROM public.eg_regional_price_retention_reference_fields() ORDER BY table_name LOOP
    EXECUTE format('LOCK TABLE public.%I IN SHARE MODE',evidence.table_name);
  END LOOP;
  LOCK TABLE public.estimate_price_periods,public.estimate_regional_price_versions IN SHARE MODE;
END; $$;

CREATE FUNCTION public.eg_regional_price_retention_references_any(version_ids bigint[], price_ids bigint[])
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

CREATE FUNCTION public.eg_regional_price_retention_referenced(version_id bigint)
RETURNS boolean LANGUAGE sql VOLATILE SET search_path=pg_catalog,public AS $$
SELECT public.eg_regional_price_retention_references_any(ARRAY[version_id],ARRAY[]::bigint[]);
$$;

CREATE FUNCTION public.eg_regional_price_retention_eligible(version_id bigint, quarters integer DEFAULT 4, failed_days integer DEFAULT 45)
RETURNS boolean LANGUAGE sql VOLATILE SET search_path=pg_catalog,public AS $$
WITH candidate AS (
  SELECT version.*,period.year,period.quarter FROM public.estimate_regional_price_versions version
  JOIN public.estimate_price_periods period ON period.id=version.period_id WHERE version.id=version_id
), published AS (
  SELECT version.id,period.year,period.quarter,
    row_number() OVER (PARTITION BY period.year,period.quarter ORDER BY version.activated_at DESC NULLS LAST,version.id DESC) AS revision,
    dense_rank() OVER (ORDER BY period.year DESC,period.quarter DESC) AS published_quarter
  FROM public.estimate_regional_price_versions version JOIN public.estimate_price_periods period ON period.id=version.period_id
  JOIN candidate ON version.source=candidate.source AND version.region_id=candidate.region_id AND version.price_zone_id=candidate.price_zone_id
  WHERE version.status IN ('active','superseded','rolled_back')
)
SELECT COALESCE((SELECT candidate.source='fgis_labor_prices'
  AND candidate.status IN ('superseded','rolled_back','failed')
  AND candidate.updated_at < CURRENT_TIMESTAMP - make_interval(days=>GREATEST(COALESCE(failed_days,45),45))
  AND (candidate.status='failed' OR (candidate.year>=1900 AND candidate.quarter BETWEEN 1 AND 4 AND candidate.activated_at IS NOT NULL))
  AND NOT EXISTS(SELECT 1 FROM published WHERE published.id=candidate.id AND revision=1
    AND published_quarter<=GREATEST(COALESCE(quarters,4),4)) FROM candidate),false);
$$;

CREATE FUNCTION public.eg_regional_price_retention_delete_fence() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$ BEGIN
  PERFORM public.eg_regional_price_retention_lock_evidence();
  RETURN NULL;
END; $$;

CREATE FUNCTION public.eg_regional_price_retention_deleted_prices_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$
DECLARE version_id bigint; version_ids bigint[]; price_ids bigint[];
BEGIN
  SELECT array_agg(DISTINCT regional_price_version_id),array_agg(id) INTO version_ids,price_ids
    FROM eg_retention_deleted_prices WHERE regional_price_version_id IS NOT NULL;
  IF version_ids IS NULL THEN RETURN NULL; END IF;
  IF current_setting('transaction_isolation')<>'read committed' THEN
    RAISE EXCEPTION 'estimate_generation.regional_price_retention_requires_read_committed';
  END IF;
  FOREACH version_id IN ARRAY version_ids LOOP
    IF NOT public.eg_regional_price_retention_eligible(version_id) THEN
      RAISE EXCEPTION 'estimate_generation.regional_price_retention_not_eligible';
    END IF;
  END LOOP;
  IF public.eg_regional_price_retention_references_any(version_ids,price_ids) THEN
    RAISE EXCEPTION 'estimate_generation.regional_price_retention_referenced';
  END IF;
  RETURN NULL;
END; $$;

CREATE TRIGGER eg_retention_resource_price_delete_fence BEFORE DELETE ON public.estimate_resource_prices
FOR EACH STATEMENT EXECUTE FUNCTION public.eg_regional_price_retention_delete_fence();
CREATE TRIGGER eg_retention_resource_price_deleted_guard AFTER DELETE ON public.estimate_resource_prices
REFERENCING OLD TABLE AS eg_retention_deleted_prices FOR EACH STATEMENT EXECUTE FUNCTION public.eg_regional_price_retention_deleted_prices_guard();
CREATE TRIGGER eg_retention_regional_version_delete_fence BEFORE DELETE ON public.estimate_regional_price_versions
FOR EACH STATEMENT EXECUTE FUNCTION public.eg_regional_price_retention_delete_fence();

CREATE FUNCTION public.eg_regional_price_retention_reference_write_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,public AS $$
DECLARE reference record; previous_payload jsonb; found_id bigint; reference_fields jsonb:=TG_ARGV[0]::jsonb;
BEGIN
  previous_payload:=CASE WHEN TG_OP='UPDATE' THEN to_jsonb(OLD) ELSE '{}'::jsonb END;
  FOR reference IN
    SELECT * FROM public.eg_regional_price_retention_row_references(to_jsonb(NEW),reference_fields)
    EXCEPT SELECT * FROM public.eg_regional_price_retention_row_references(previous_payload,reference_fields)
    ORDER BY reference_kind,reference_id
  LOOP
    IF length(reference.reference_id)>19 OR reference.reference_id::numeric>9223372036854775807 THEN
      RAISE EXCEPTION 'estimate_generation.regional_price_reference_missing';
    END IF;
    IF reference.reference_kind='price' THEN
      SELECT id INTO found_id FROM public.estimate_resource_prices WHERE id=reference.reference_id::bigint FOR KEY SHARE;
    ELSE
      SELECT id INTO found_id FROM public.estimate_regional_price_versions WHERE id=reference.reference_id::bigint FOR KEY SHARE;
    END IF;
    IF found_id IS NULL THEN RAISE EXCEPTION 'estimate_generation.regional_price_reference_missing'; END IF;
  END LOOP;
  RETURN NEW;
END; $$;

DO $$ DECLARE evidence record; BEGIN
  FOR evidence IN SELECT * FROM public.eg_regional_price_retention_reference_fields() LOOP
    EXECUTE format('CREATE TRIGGER eg_retention_reference_write_guard BEFORE INSERT OR UPDATE ON public.%I
      FOR EACH ROW EXECUTE FUNCTION public.eg_regional_price_retention_reference_write_guard(%L)',evidence.table_name,evidence.fields::text);
  END LOOP;
END; $$;
SQL);

        foreach (self::GUARDS as $guard) {
            $definition = DB::scalar("SELECT pg_get_functiondef('public.{$guard}()'::regprocedure)");
            if (! is_string($definition) || preg_match('/\bBEGIN\b/', $definition, $begin, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('estimate_generation.regional_price_retention_guard_contract_changed');
            }
            $offset = $begin[0][1] + strlen($begin[0][0]);
            DB::unprepared(substr_replace($definition, $this->deleteBranch($guard), $offset, 0));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::GUARDS as $guard) {
            $definition = DB::scalar("SELECT pg_get_functiondef('public.{$guard}()'::regprocedure)");
            $branch = $this->deleteBranch($guard);
            if (! is_string($definition) || substr_count($definition, $branch) !== 1) {
                throw new RuntimeException('estimate_generation.regional_price_retention_rollback_contract_changed');
            }
            DB::unprepared(str_replace($branch, '', $definition));
        }

        DB::unprepared(<<<'SQL'
DO $$ DECLARE evidence record; BEGIN
  FOR evidence IN SELECT relation.relname FROM pg_trigger trigger_row
    JOIN pg_class relation ON relation.oid=trigger_row.tgrelid
    JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace
    WHERE namespace.nspname='public' AND trigger_row.tgname='eg_retention_reference_write_guard'
  LOOP EXECUTE format('DROP TRIGGER eg_retention_reference_write_guard ON public.%I',evidence.relname); END LOOP;
END; $$;
DROP TRIGGER eg_retention_resource_price_delete_fence ON public.estimate_resource_prices;
DROP TRIGGER eg_retention_resource_price_deleted_guard ON public.estimate_resource_prices;
DROP TRIGGER eg_retention_regional_version_delete_fence ON public.estimate_regional_price_versions;
DROP FUNCTION public.eg_regional_price_retention_reference_write_guard();
DROP FUNCTION public.eg_regional_price_retention_deleted_prices_guard();
DROP FUNCTION public.eg_regional_price_retention_delete_fence();
DROP FUNCTION public.eg_regional_price_retention_eligible(bigint,integer,integer);
DROP FUNCTION public.eg_regional_price_retention_referenced(bigint);
DROP FUNCTION public.eg_regional_price_retention_references_any(bigint[],bigint[]);
DROP FUNCTION public.eg_regional_price_retention_lock_evidence();
DROP FUNCTION public.eg_regional_price_retention_reference_fields();
DROP FUNCTION public.eg_regional_price_retention_row_references(jsonb,jsonb);
DROP FUNCTION public.eg_regional_price_retention_json_references(jsonb,boolean);
SQL);
    }

    private function deleteBranch(string $guard): string
    {
        $branch = <<<'SQL'

  IF TG_OP='DELETE' AND TG_TABLE_NAME='estimate_resource_prices' THEN
    IF OLD.regional_price_version_id IS NOT NULL THEN
      RETURN OLD;
    END IF;
  END IF;
SQL;
        if ($guard === 'eg_used_pricing_source_guard') {
            $branch .= <<<'SQL'

  IF TG_OP='DELETE' AND TG_TABLE_NAME='estimate_regional_price_versions' THEN
    IF current_setting('transaction_isolation')<>'read committed' THEN
      RAISE EXCEPTION 'estimate_generation.regional_price_retention_requires_read_committed';
    END IF;
    IF NOT public.eg_regional_price_retention_eligible(OLD.id)
      OR public.eg_regional_price_retention_referenced(OLD.id)
      OR EXISTS(SELECT 1 FROM public.estimate_resource_prices WHERE regional_price_version_id=OLD.id) THEN
      RAISE EXCEPTION 'estimate_generation.regional_price_retention_not_eligible';
    END IF;
    RETURN OLD;
  END IF;
SQL;
        }

        return $branch."\n";
    }
};
