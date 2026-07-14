<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('estimate_generation_truthful_settings_schema_requires_postgresql');
        }

        DB::statement("SET LOCAL lock_timeout = '2s'");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_setting_snapshot_valid_v2(payload jsonb, daily numeric, monthly numeric, money_currency text)
RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
  stage text;
  threshold text;
  format_name text;
BEGIN
  IF jsonb_typeof(payload) <> 'object' OR octet_length(payload::text) > 65536
    OR lower(payload::text) ~ '(api[_-]?key|secret|credential|password|bearer|raw[_-]?prompt|endpoint|access[_-]?token)'
    OR NOT payload ?& ARRAY['schema_version','models','limits','timeouts','retries','confidence','enabled_formats','manual_review','budgets']
    OR (payload - ARRAY['schema_version','models','limits','timeouts','retries','confidence','enabled_formats','manual_review','budgets']) <> '{}'::jsonb
    OR payload->>'schema_version' <> '2' THEN
    RETURN false;
  END IF;
  IF jsonb_typeof(payload->'models') <> 'object'
    OR NOT (payload->'models') ?& ARRAY['vision','classification','normative_matching']
    OR ((payload->'models') - ARRAY['vision','classification','normative_matching']) <> '{}'::jsonb
    OR jsonb_typeof(payload->'timeouts') <> 'object'
    OR NOT (payload->'timeouts') ?& ARRAY['vision','classification','normative_matching']
    OR ((payload->'timeouts') - ARRAY['vision','classification','normative_matching']) <> '{}'::jsonb
    OR jsonb_typeof(payload->'retries') <> 'object'
    OR NOT (payload->'retries') ?& ARRAY['vision','classification','normative_matching']
    OR ((payload->'retries') - ARRAY['vision','classification','normative_matching']) <> '{}'::jsonb THEN
    RETURN false;
  END IF;
  FOREACH stage IN ARRAY ARRAY['vision','classification','normative_matching'] LOOP
    IF jsonb_typeof(payload->'models'->stage) <> 'string'
      OR (payload->'models'->>stage) !~ '^[A-Za-z0-9][A-Za-z0-9._-]{1,63}/[A-Za-z0-9][A-Za-z0-9._:-]{1,127}$'
      OR jsonb_typeof(payload->'timeouts'->stage) <> 'number'
      OR (payload->'timeouts'->>stage) !~ '^[0-9]+$'
      OR (payload->'timeouts'->>stage)::integer NOT BETWEEN 1 AND 3600
      OR jsonb_typeof(payload->'retries'->stage) <> 'number'
      OR (payload->'retries'->>stage) !~ '^[0-9]+$'
      OR (payload->'retries'->>stage)::integer NOT BETWEEN 0 AND 5 THEN
      RETURN false;
    END IF;
  END LOOP;
  IF jsonb_typeof(payload->'limits') <> 'object'
    OR NOT (payload->'limits') ?& ARRAY['max_files','max_pages_per_file','max_total_pages']
    OR ((payload->'limits') - ARRAY['max_files','max_pages_per_file','max_total_pages']) <> '{}'::jsonb
    OR (payload #>> '{limits,max_files}') !~ '^[0-9]+$'
    OR (payload #>> '{limits,max_pages_per_file}') !~ '^[0-9]+$'
    OR (payload #>> '{limits,max_total_pages}') !~ '^[0-9]+$'
    OR (payload #>> '{limits,max_files}')::integer NOT BETWEEN 1 AND 100
    OR (payload #>> '{limits,max_pages_per_file}')::integer NOT BETWEEN 1 AND 2000
    OR (payload #>> '{limits,max_total_pages}')::integer NOT BETWEEN 1 AND 10000 THEN
    RETURN false;
  END IF;
  IF jsonb_typeof(payload->'confidence') <> 'object'
    OR NOT (payload->'confidence') ?& ARRAY['classification','geometry','normative_matching']
    OR ((payload->'confidence') - ARRAY['classification','geometry','normative_matching']) <> '{}'::jsonb THEN
    RETURN false;
  END IF;
  FOREACH stage IN ARRAY ARRAY['classification','geometry','normative_matching'] LOOP
    threshold := payload->'confidence'->>stage;
    IF jsonb_typeof(payload->'confidence'->stage) <> 'string'
      OR threshold !~ '^(0(\.[0-9]{1,4})?|1(\.0{1,4})?)$' THEN
      RETURN false;
    END IF;
  END LOOP;
  IF jsonb_typeof(payload->'enabled_formats') <> 'array'
    OR jsonb_array_length(payload->'enabled_formats') NOT BETWEEN 1 AND 8
    OR (SELECT count(*) FROM jsonb_array_elements_text(payload->'enabled_formats'))
      <> (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(payload->'enabled_formats')) THEN
    RETURN false;
  END IF;
  FOR format_name IN SELECT value FROM jsonb_array_elements_text(payload->'enabled_formats') LOOP
    IF format_name NOT IN ('pdf','jpg','jpeg','png','tiff','dxf','dwg','xlsx') THEN
      RETURN false;
    END IF;
  END LOOP;
  IF jsonb_typeof(payload->'manual_review') <> 'object'
    OR NOT (payload->'manual_review') ?& ARRAY['low_confidence']
    OR ((payload->'manual_review') - ARRAY['low_confidence']) <> '{}'::jsonb
    OR jsonb_typeof(payload->'manual_review'->'low_confidence') <> 'boolean' THEN
    RETURN false;
  END IF;
  IF jsonb_typeof(payload->'budgets') <> 'object'
    OR NOT (payload->'budgets') ?& ARRAY['daily','monthly','currency']
    OR ((payload->'budgets') - ARRAY['daily','monthly','currency']) <> '{}'::jsonb
    OR (payload #>> '{budgets,daily}') !~ '^(0|[1-9][0-9]{0,17})\.[0-9]{2}$'
    OR (payload #>> '{budgets,monthly}') !~ '^(0|[1-9][0-9]{0,17})\.[0-9]{2}$'
    OR (payload #>> '{budgets,currency}') NOT IN ('RUB','USD','EUR')
    OR (payload #>> '{budgets,daily}')::numeric <> daily
    OR (payload #>> '{budgets,monthly}')::numeric <> monthly
    OR (payload #>> '{budgets,currency}') <> money_currency THEN
    RETURN false;
  END IF;
  RETURN true;
EXCEPTION WHEN others THEN
  RETURN false;
END;
$$;
SQL);
        DB::statement('ALTER TABLE estimate_generation_setting_snapshots DROP CONSTRAINT IF EXISTS eg_setting_snapshot_shape_ck');
        DB::statement('DROP FUNCTION eg_setting_snapshot_valid_v1(jsonb, numeric, numeric, text)');
        DB::statement('ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_shape_v2_ck CHECK (eg_setting_snapshot_valid_v2(snapshot, daily_budget, monthly_budget, currency)) NOT VALID');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_pin_ai_operation_settings(p_correlation uuid, p_organization bigint, p_session bigint)
RETURNS TABLE(global_snapshot_id bigint, effective_snapshot_id bigint) LANGUAGE plpgsql AS $$
DECLARE v_existing estimate_generation_ai_operations%ROWTYPE; v_global bigint; v_effective bigint; v_fingerprint text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-operation:' || p_correlation::text, 0));
  SELECT * INTO v_existing FROM estimate_generation_ai_operations WHERE correlation_id = p_correlation;
  IF FOUND THEN
    IF v_existing.organization_id <> p_organization OR v_existing.session_id <> p_session THEN
      RAISE EXCEPTION 'estimate_generation_ai_operation_identity_conflict';
    END IF;
    RETURN QUERY SELECT v_existing.global_setting_snapshot_id, v_existing.effective_setting_snapshot_id;
    RETURN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM estimate_generation_sessions WHERE id = p_session AND organization_id = p_organization) THEN
    RAISE EXCEPTION 'estimate_generation_ai_operation_scope_invalid';
  END IF;
  SELECT id INTO STRICT v_global FROM estimate_generation_setting_snapshots
    WHERE scope = 'global' AND organization_id IS NULL AND snapshot->>'schema_version' = '2'
    ORDER BY version DESC LIMIT 1;
  SELECT id INTO v_effective FROM estimate_generation_setting_snapshots
    WHERE scope = 'organization' AND organization_id = p_organization AND snapshot->>'schema_version' = '2'
    ORDER BY version DESC LIMIT 1;
  v_effective := COALESCE(v_effective, v_global);
  v_fingerprint := 'sha256:' || encode(pg_catalog.sha256(pg_catalog.convert_to(
    p_correlation::text || '|' || p_organization::text || '|' || p_session::text || '|' || v_global::text || '|' || v_effective::text,
    'UTF8'
  )), 'hex');
  INSERT INTO estimate_generation_ai_operations
    (correlation_id, organization_id, session_id, global_setting_snapshot_id, effective_setting_snapshot_id, immutable_fingerprint, created_at)
  VALUES (p_correlation, p_organization, p_session, v_global, v_effective, v_fingerprint, now());
  RETURN QUERY SELECT v_global, v_effective;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('estimate_generation_truthful_settings_schema_is_forward_only');
    }
};
