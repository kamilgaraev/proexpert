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
        Schema::create('estimate_generation_setting_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope', 16);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->decimal('daily_budget', 20, 2);
            $table->decimal('monthly_budget', 20, 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('created_by_system_admin_id');
            $table->timestampTz('created_at');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('created_by_system_admin_id')->references('id')->on('system_admins')->restrictOnDelete();
            $table->index(['scope', 'organization_id', 'version'], 'eg_setting_snapshot_lookup_idx');
        });

        Schema::create('estimate_generation_setting_audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('setting_snapshot_id');
            $table->string('scope', 16);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('actor_system_admin_id');
            $table->string('key', 64);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->char('command_fingerprint', 71);
            $table->timestampTz('created_at');
            $table->foreign('setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('actor_system_admin_id')->references('id')->on('system_admins')->restrictOnDelete();
            $table->index(['scope', 'organization_id', 'created_at'], 'eg_setting_audit_scope_time_idx');
        });

        Schema::create('estimate_generation_setting_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope', 16);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('idempotency_key', 80);
            $table->char('command_fingerprint', 71);
            $table->string('status', 16);
            $table->jsonb('result')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('completed_at')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('estimate_generation_admin_action_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('operation', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('idempotency_key', 80);
            $table->char('command_fingerprint', 71);
            $table->string('status', 16);
            $table->jsonb('result')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unique(['organization_id', 'operation', 'idempotency_key'], 'eg_admin_action_idempotency_uq');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('estimate_generation_admin_action_audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('actor_system_admin_id');
            $table->string('operation', 40);
            $table->unsignedBigInteger('subject_id');
            $table->char('command_fingerprint', 71);
            $table->jsonb('result');
            $table->timestampTz('created_at');
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('actor_system_admin_id')->references('id')->on('system_admins')->restrictOnDelete();
            $table->index(['organization_id', 'operation', 'created_at'], 'eg_admin_action_audit_lookup_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX eg_setting_snapshot_version_uq ON estimate_generation_setting_snapshots (scope, organization_id, version) NULLS NOT DISTINCT');
        DB::statement('CREATE UNIQUE INDEX eg_setting_operation_idempotency_uq ON estimate_generation_setting_operations (scope, organization_id, idempotency_key) NULLS NOT DISTINCT');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_setting_snapshot_valid_v1(payload jsonb, daily numeric, monthly numeric, money_currency text) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
  stage text;
  threshold text;
  format_name text;
BEGIN
  IF jsonb_typeof(payload) <> 'object' OR octet_length(payload::text) > 65536
    OR lower(payload::text) ~ '(api[_-]?key|secret|credential|password|bearer|raw[_-]?prompt|endpoint|access[_-]?token)'
    OR NOT payload ?& ARRAY['schema_version','models','limits','timeouts','retries','confidence','enabled_formats','manual_review','budgets']
    OR (payload - ARRAY['schema_version','models','limits','timeouts','retries','confidence','enabled_formats','manual_review','budgets']) <> '{}'::jsonb
    OR payload->>'schema_version' <> '1' THEN
    RETURN false;
  END IF;
  IF jsonb_typeof(payload->'models') <> 'object'
    OR NOT (payload->'models') ?& ARRAY['vision','classification','planning','normative_matching','pricing']
    OR ((payload->'models') - ARRAY['vision','classification','planning','normative_matching','pricing']) <> '{}'::jsonb
    OR jsonb_typeof(payload->'timeouts') <> 'object'
    OR NOT (payload->'timeouts') ?& ARRAY['vision','classification','planning','normative_matching','pricing']
    OR ((payload->'timeouts') - ARRAY['vision','classification','planning','normative_matching','pricing']) <> '{}'::jsonb
    OR jsonb_typeof(payload->'retries') <> 'object'
    OR NOT (payload->'retries') ?& ARRAY['vision','classification','planning','normative_matching','pricing']
    OR ((payload->'retries') - ARRAY['vision','classification','planning','normative_matching','pricing']) <> '{}'::jsonb THEN
    RETURN false;
  END IF;
  FOREACH stage IN ARRAY ARRAY['vision','classification','planning','normative_matching','pricing'] LOOP
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
    OR NOT (payload->'confidence') ?& ARRAY['classification','geometry','normative_matching','pricing']
    OR ((payload->'confidence') - ARRAY['classification','geometry','normative_matching','pricing']) <> '{}'::jsonb THEN
    RETURN false;
  END IF;
  FOREACH stage IN ARRAY ARRAY['classification','geometry','normative_matching','pricing'] LOOP
    threshold := payload->'confidence'->>stage;
    IF jsonb_typeof(payload->'confidence'->stage) <> 'string' OR threshold !~ '^(0(\.[0-9]{1,4})?|1(\.0{1,4})?)$' THEN RETURN false; END IF;
  END LOOP;
  IF jsonb_typeof(payload->'enabled_formats') <> 'array'
    OR jsonb_array_length(payload->'enabled_formats') NOT BETWEEN 1 AND 8
    OR (SELECT count(*) FROM jsonb_array_elements_text(payload->'enabled_formats')) <> (SELECT count(DISTINCT value) FROM jsonb_array_elements_text(payload->'enabled_formats')) THEN
    RETURN false;
  END IF;
  FOR format_name IN SELECT value FROM jsonb_array_elements_text(payload->'enabled_formats') LOOP
    IF format_name NOT IN ('pdf','jpg','jpeg','png','tiff','dxf','dwg','xlsx') THEN RETURN false; END IF;
  END LOOP;
  IF jsonb_typeof(payload->'manual_review') <> 'object'
    OR NOT (payload->'manual_review') ?& ARRAY['low_confidence','missing_evidence','price_outlier','normative_fallback']
    OR ((payload->'manual_review') - ARRAY['low_confidence','missing_evidence','price_outlier','normative_fallback']) <> '{}'::jsonb THEN
    RETURN false;
  END IF;
  FOREACH stage IN ARRAY ARRAY['low_confidence','missing_evidence','price_outlier','normative_fallback'] LOOP
    IF jsonb_typeof(payload->'manual_review'->stage) <> 'boolean' THEN RETURN false; END IF;
  END LOOP;
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
        DB::statement('ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_shape_ck CHECK (eg_setting_snapshot_valid_v1(snapshot, daily_budget, monthly_budget, currency))');
        DB::statement("ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_scope_ck CHECK (scope IN ('global','organization') AND ((scope = 'global' AND organization_id IS NULL) OR (scope = 'organization' AND organization_id IS NOT NULL)))");
        DB::statement("ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_currency_ck CHECK (currency IN ('RUB','USD','EUR'))");
        DB::statement('ALTER TABLE estimate_generation_setting_snapshots ADD CONSTRAINT eg_setting_snapshot_budget_ck CHECK (daily_budget >= 0 AND monthly_budget >= daily_budget)');
        DB::statement("ALTER TABLE estimate_generation_setting_audits ADD CONSTRAINT eg_setting_audit_scope_ck CHECK (scope IN ('global','organization') AND ((scope = 'global' AND organization_id IS NULL) OR (scope = 'organization' AND organization_id IS NOT NULL)))");
        DB::statement("ALTER TABLE estimate_generation_setting_audits ADD CONSTRAINT eg_setting_audit_key_ck CHECK (key IN ('models','limits','timeouts','retries','confidence','enabled_formats','manual_review','budgets'))");
        DB::statement("ALTER TABLE estimate_generation_setting_audits ADD CONSTRAINT eg_setting_audit_value_ck CHECK (octet_length(COALESCE(old_value::text, 'null')) <= 65536 AND octet_length(new_value::text) <= 65536 AND lower(COALESCE(old_value::text, '') || new_value::text) !~ '(api[_-]?key|secret|credential|password|bearer|raw[_-]?prompt|endpoint|access[_-]?token)')");
        DB::statement("ALTER TABLE estimate_generation_setting_audits ADD CONSTRAINT eg_setting_audit_fingerprint_ck CHECK (command_fingerprint ~ '^sha256:[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE estimate_generation_setting_operations ADD CONSTRAINT eg_setting_operation_scope_ck CHECK (scope IN ('global','organization') AND ((scope = 'global' AND organization_id IS NULL) OR (scope = 'organization' AND organization_id IS NOT NULL)))");
        DB::statement("ALTER TABLE estimate_generation_setting_operations ADD CONSTRAINT eg_setting_operation_idempotency_ck CHECK (idempotency_key ~ '^[A-Za-z0-9._:-]{16,80}$')");
        DB::statement("ALTER TABLE estimate_generation_setting_operations ADD CONSTRAINT eg_setting_operation_fingerprint_ck CHECK (command_fingerprint ~ '^sha256:[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE estimate_generation_setting_operations ADD CONSTRAINT eg_setting_operation_state_ck CHECK ((status = 'pending' AND result IS NULL AND completed_at IS NULL) OR (status = 'completed' AND result IS NOT NULL AND completed_at IS NOT NULL))");
        DB::unprepared("ALTER TABLE estimate_generation_setting_operations ADD CONSTRAINT eg_setting_operation_result_ck CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND result ?& ARRAY['snapshot_id','version'] AND (result - ARRAY['snapshot_id','version']) = '{}'::jsonb AND octet_length(result::text) <= 256))");
        DB::statement("ALTER TABLE estimate_generation_admin_action_operations ADD CONSTRAINT eg_admin_action_operation_ck CHECK (operation IN ('dataset_process','dataset_submit_review','dataset_approve_review','dataset_reject_review','benchmark_run'))");
        DB::statement("ALTER TABLE estimate_generation_admin_action_operations ADD CONSTRAINT eg_admin_action_state_ck CHECK ((status = 'pending' AND result IS NULL AND completed_at IS NULL) OR (status = 'completed' AND result IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE estimate_generation_admin_action_operations ADD CONSTRAINT eg_admin_action_identity_ck CHECK (subject_id > 0 AND idempotency_key ~ '^[A-Za-z0-9._:-]{16,80}$' AND command_fingerprint ~ '^sha256:[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE estimate_generation_admin_action_operations ADD CONSTRAINT eg_admin_action_result_ck CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 1024 AND lower(result::text) !~ '(api[_-]?key|secret|credential|password|bearer|raw[_-]?prompt|endpoint|access[_-]?token)'))");
        DB::statement("ALTER TABLE estimate_generation_admin_action_audits ADD CONSTRAINT eg_admin_action_audit_ck CHECK (operation IN ('dataset_process','dataset_submit_review','dataset_approve_review','dataset_reject_review','benchmark_run') AND subject_id > 0 AND command_fingerprint ~ '^sha256:[a-f0-9]{64}$' AND jsonb_typeof(result) = 'object' AND octet_length(result::text) <= 1024 AND lower(result::text) !~ '(api[_-]?key|secret|credential|password|bearer|raw[_-]?prompt|endpoint|access[_-]?token)')");

        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_setting_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'estimate_generation settings snapshots and audits are immutable';
END;
$$;
CREATE TRIGGER eg_setting_snapshot_immutable BEFORE UPDATE OR DELETE ON estimate_generation_setting_snapshots FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable();
CREATE TRIGGER eg_setting_audit_immutable BEFORE UPDATE OR DELETE ON estimate_generation_setting_audits FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable();
CREATE TRIGGER eg_admin_action_audit_immutable BEFORE UPDATE OR DELETE ON estimate_generation_admin_action_audits FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable();
SQL);

    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS eg_admin_action_audit_immutable ON estimate_generation_admin_action_audits; DROP TRIGGER IF EXISTS eg_setting_audit_immutable ON estimate_generation_setting_audits; DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable ON estimate_generation_setting_snapshots;');
        }
        Schema::dropIfExists('estimate_generation_admin_action_audits');
        Schema::dropIfExists('estimate_generation_admin_action_operations');
        Schema::dropIfExists('estimate_generation_setting_operations');
        Schema::dropIfExists('estimate_generation_setting_audits');
        Schema::dropIfExists('estimate_generation_setting_snapshots');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS eg_setting_immutable(); DROP FUNCTION IF EXISTS eg_setting_snapshot_valid_v1(jsonb, numeric, numeric, text);');
        }
    }
};
