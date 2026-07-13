<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("SET lock_timeout = '5s'");
        DB::statement("SET statement_timeout = '15min'");
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs ADD COLUMN case_results_size bigint, ADD COLUMN case_results_sha256 char(64), ADD COLUMN case_results_etag text, ADD COLUMN case_results_version text, ADD COLUMN case_results_content_type text, ADD COLUMN error_summary text');
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT eg_benchmark_closed_state_chk');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_closed_state_chk CHECK (
 (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND error_summary IS NULL AND cost_amount = 0)
 OR
 (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND error_summary IS NULL AND cost_amount >= 0 AND currency ~ '^[A-Z]{3}$' AND (
   (case_results IS NOT NULL AND jsonb_typeof(case_results) = 'array' AND case_results <> '[]'::jsonb AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL)
   OR
   (case_results IS NULL AND case_results_storage_disk = 's3' AND case_results_storage_path IS NOT NULL AND case_results_size > 0 AND case_results_sha256 ~ '^[a-f0-9]{64}$' AND case_results_content_type IS NOT NULL AND btrim(case_results_content_type) <> '' AND (case_results_etag IS NOT NULL OR case_results_version IS NOT NULL))
 ))
 OR
 (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND length(btrim(failure_code)) BETWEEN 1 AND 100 AND error_summary IS NOT NULL AND length(btrim(error_summary)) BETWEEN 1 AND 500 AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND cost_amount = 0)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_training_dataset_chain() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior estimate_generation_training_datasets%ROWTYPE; expected integer;
BEGIN
  IF NEW.version = 1 THEN RETURN NEW; END IF;
  SELECT * INTO prior FROM estimate_generation_training_datasets WHERE organization_id = NEW.organization_id AND dataset_key = NEW.dataset_key ORDER BY version DESC LIMIT 1 FOR UPDATE;
  expected := COALESCE(prior.version, 0) + 1;
  IF NEW.version <> expected OR NEW.dataset_type IS DISTINCT FROM prior.dataset_type OR NEW.project_id IS DISTINCT FROM prior.project_id OR NEW.scope IS DISTINCT FROM prior.scope THEN
    RAISE EXCEPTION 'invalid dataset version chain';
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_training_dataset_chain ON estimate_generation_training_datasets;
CREATE TRIGGER eg_training_dataset_chain BEFORE INSERT ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_guard_training_dataset_chain();

CREATE OR REPLACE FUNCTION eg_guard_reviewed_example_content() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.reviewed_at IS NOT NULL AND (NEW.raw_payload IS DISTINCT FROM OLD.raw_payload OR NEW.source_row_hash IS DISTINCT FROM OLD.source_row_hash OR NEW.work_name IS DISTINCT FROM OLD.work_name OR NEW.work_unit IS DISTINCT FROM OLD.work_unit OR NEW.work_quantity IS DISTINCT FROM OLD.work_quantity OR NEW.norm_code IS DISTINCT FROM OLD.norm_code OR NEW.source_refs IS DISTINCT FROM OLD.source_refs) THEN
    RAISE EXCEPTION 'reviewed training example content is immutable';
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_reviewed_example_content ON estimate_generation_training_examples;
CREATE TRIGGER eg_reviewed_example_content BEFORE UPDATE ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_guard_reviewed_example_content();

CREATE OR REPLACE FUNCTION eg_guard_benchmark_run_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF OLD.status <> 'running' OR NEW.status NOT IN ('completed','failed') THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF (to_jsonb(NEW) - 'status' - 'metrics' - 'case_results' - 'case_results_storage_disk' - 'case_results_storage_path' - 'case_results_size' - 'case_results_sha256' - 'case_results_etag' - 'case_results_version' - 'case_results_version_scheme' - 'case_results_content_type' - 'duration_ms' - 'cost_amount' - 'failure_code' - 'error_summary' - 'completed_at' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'metrics' - 'case_results' - 'case_results_storage_disk' - 'case_results_storage_path' - 'case_results_size' - 'case_results_sha256' - 'case_results_etag' - 'case_results_version' - 'case_results_version_scheme' - 'case_results_content_type' - 'duration_ms' - 'cost_amount' - 'failure_code' - 'error_summary' - 'completed_at' - 'updated_at') THEN RAISE EXCEPTION 'immutable benchmark manifest'; END IF;
  RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS eg_reviewed_example_content ON estimate_generation_training_examples; DROP FUNCTION IF EXISTS eg_guard_reviewed_example_content(); DROP TRIGGER IF EXISTS eg_training_dataset_chain ON estimate_generation_training_datasets; DROP FUNCTION IF EXISTS eg_guard_training_dataset_chain();');
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_closed_state_chk');
        DB::statement("ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_closed_state_chk CHECK ((status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_path IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND cost_amount = 0) OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND ((case_results IS NOT NULL AND case_results_storage_path IS NULL) OR (case_results IS NULL AND case_results_storage_path IS NOT NULL))) OR (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_path IS NULL AND duration_ms IS NULL AND cost_amount = 0))");
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP COLUMN IF EXISTS case_results_size, DROP COLUMN IF EXISTS case_results_sha256, DROP COLUMN IF EXISTS case_results_etag, DROP COLUMN IF EXISTS case_results_version, DROP COLUMN IF EXISTS case_results_content_type, DROP COLUMN IF EXISTS error_summary');
    }
};
