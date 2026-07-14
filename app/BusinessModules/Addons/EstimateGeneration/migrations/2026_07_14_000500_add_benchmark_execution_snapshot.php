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
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs ADD COLUMN IF NOT EXISTS execution_snapshot jsonb');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_benchmark_execution_snapshot_valid_v1(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
SELECT jsonb_typeof(value) = 'object'
 AND (SELECT array_agg(key ORDER BY key) FROM jsonb_object_keys(value) key) = ARRAY['adapter_id','currency','dataset_content_hash','dataset_id','dataset_type','dataset_version','manifest_locator','manifest_sha256','model_versions','normative_version','organization_id','pipeline_version','price_version','prompt_version','schema_version','settings_snapshot_id','settings_snapshot_version']::text[]
 AND value->>'schema_version' = '1'
 AND (value->>'organization_id') ~ '^[1-9][0-9]*$'
 AND (value->>'dataset_id') ~ '^[1-9][0-9]*$'
 AND value->>'dataset_type' IN ('development','regression','acceptance')
 AND (value->>'dataset_version') ~ '^[1-9][0-9]*$'
 AND (value->>'dataset_content_hash') ~ '^sha256:[a-f0-9]{64}$'
 AND (value->>'manifest_sha256') ~ '^[a-f0-9]{64}$'
 AND (value->>'manifest_locator') ~ ('^s3://org-' || (value->>'organization_id') || '/estimate-generation/benchmarks/' || (value->>'dataset_type') || '/[A-Za-z0-9._/-]+[.]json$')
 AND position('..' in value->>'manifest_locator') = 0 AND position('?' in value->>'manifest_locator') = 0
 AND (value->>'adapter_id') ~ '^[a-z][a-z0-9-]{2,63}$'
 AND (value->>'settings_snapshot_id') ~ '^[1-9][0-9]*$'
 AND (value->>'settings_snapshot_version') ~ '^[1-9][0-9]*$'
 AND jsonb_typeof(value->'model_versions') = 'object'
 AND value->>'currency' IN ('RUB','USD','EUR')
$$;
ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_execution_snapshot_ck CHECK (execution_snapshot IS NULL OR eg_benchmark_execution_snapshot_valid_v1(execution_snapshot)) NOT VALID;
CREATE OR REPLACE FUNCTION eg_validate_benchmark_dataset() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dataset_row estimate_generation_training_datasets%ROWTYPE;
BEGIN
  SELECT * INTO dataset_row FROM estimate_generation_training_datasets WHERE id = NEW.training_dataset_id;
  IF dataset_row.organization_id <> NEW.organization_id OR dataset_row.version <> NEW.dataset_version OR dataset_row.status <> 'approved' THEN RAISE EXCEPTION 'invalid benchmark dataset scope or version'; END IF;
  IF NOT eg_benchmark_execution_snapshot_valid_v1(NEW.execution_snapshot)
     OR (NEW.execution_snapshot->>'organization_id')::bigint <> NEW.organization_id
     OR (NEW.execution_snapshot->>'dataset_id')::bigint <> NEW.training_dataset_id
     OR (NEW.execution_snapshot->>'dataset_version')::integer <> NEW.dataset_version
     OR NEW.execution_snapshot->>'dataset_type' <> dataset_row.dataset_type THEN
    RAISE EXCEPTION 'invalid benchmark execution snapshot';
  END IF;
  RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION eg_guard_benchmark_run_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF OLD.status <> 'running' OR NEW.status NOT IN ('completed','failed') THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF NEW.uuid <> OLD.uuid OR NEW.organization_id <> OLD.organization_id OR NEW.training_dataset_id <> OLD.training_dataset_id OR NEW.dataset_version <> OLD.dataset_version OR NEW.pipeline_version <> OLD.pipeline_version OR NEW.model_versions <> OLD.model_versions OR NEW.normative_version <> OLD.normative_version OR NEW.price_version <> OLD.price_version OR NEW.currency <> OLD.currency OR NEW.execution_snapshot <> OLD.execution_snapshot OR NEW.started_at <> OLD.started_at THEN RAISE EXCEPTION 'immutable benchmark manifest'; END IF;
  RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_execution_snapshot_ck');
            DB::statement('DROP FUNCTION IF EXISTS eg_benchmark_execution_snapshot_valid_v1(jsonb)');
        }
    }
};
