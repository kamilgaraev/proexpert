<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN processing_token uuid');
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs ADD COLUMN case_results_version_scheme text');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS projects_id_organization_uq ON projects (id, organization_id)');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS estimate_generation_training_datasets_project_id_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_dataset_project_tenant_fk FOREIGN KEY (project_id, organization_id) REFERENCES projects(id, organization_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE estimate_generation_training_examples ADD CONSTRAINT eg_training_example_review_pair_chk CHECK ((reviewed_by IS NULL AND reviewed_at IS NULL) OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_processing_token_chk CHECK ((status = 'processing' AND processing_token IS NULL OR processing_token IS NOT NULL) OR (status <> 'processing' AND processing_token IS NULL))");
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT eg_benchmark_closed_state_chk');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_closed_state_chk CHECK (
 (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND error_summary IS NULL AND cost_amount = 0)
 OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND error_summary IS NULL AND cost_amount >= 0 AND currency ~ '^[A-Z]{3}$' AND (
   (case_results IS NOT NULL AND jsonb_typeof(case_results) = 'array' AND case_results <> '[]'::jsonb AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL)
   OR (case_results IS NULL AND case_results_storage_disk = 's3' AND case_results_storage_path ~ ('^org-' || organization_id::text || '/estimate-generation/benchmarks/' || uuid::text || '/[a-f0-9]{64}\.json$') AND case_results_size > 0 AND case_results_size <= 64000000 AND case_results_sha256 ~ '^[a-f0-9]{64}$' AND case_results_storage_path LIKE ('%/' || case_results_sha256 || '.json') AND case_results_content_type = 'application/json' AND case_results_version_scheme = 'sha256')
 ))
 OR (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND length(btrim(failure_code)) BETWEEN 1 AND 100 AND error_summary IS NOT NULL AND length(btrim(error_summary)) BETWEEN 1 AND 500 AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND cost_amount = 0)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_reviewed_example_content() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (OLD.reviewed_by IS NOT NULL OR OLD.reviewed_at IS NOT NULL) AND
     ((to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at')) THEN
    RAISE EXCEPTION 'reviewed training example is immutable';
  END IF;
  RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_closed_state_chk');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS eg_training_example_review_pair_chk');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_processing_token_chk');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_dataset_project_tenant_fk');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS estimate_generation_training_datasets_project_id_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT estimate_generation_training_datasets_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_token');
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP COLUMN IF EXISTS case_results_version_scheme');
        DB::statement('DROP INDEX IF EXISTS projects_id_organization_uq');
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_closed_state_chk CHECK (
 (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND error_summary IS NULL AND cost_amount = 0)
 OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND error_summary IS NULL AND cost_amount >= 0 AND currency ~ '^[A-Z]{3}$' AND ((case_results IS NOT NULL AND jsonb_typeof(case_results) = 'array' AND case_results <> '[]'::jsonb AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL) OR (case_results IS NULL AND case_results_storage_disk = 's3' AND case_results_storage_path IS NOT NULL AND case_results_size > 0 AND case_results_sha256 ~ '^[a-f0-9]{64}$' AND case_results_content_type IS NOT NULL AND btrim(case_results_content_type) <> '' AND (case_results_etag IS NOT NULL OR case_results_version IS NOT NULL))))
 OR (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND length(btrim(failure_code)) BETWEEN 1 AND 100 AND error_summary IS NOT NULL AND length(btrim(error_summary)) BETWEEN 1 AND 500 AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND cost_amount = 0)
)
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_reviewed_example_content() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.reviewed_at IS NOT NULL AND (NEW.raw_payload IS DISTINCT FROM OLD.raw_payload OR NEW.source_row_hash IS DISTINCT FROM OLD.source_row_hash OR NEW.work_name IS DISTINCT FROM OLD.work_name OR NEW.work_unit IS DISTINCT FROM OLD.work_unit OR NEW.work_quantity IS DISTINCT FROM OLD.work_quantity OR NEW.norm_code IS DISTINCT FROM OLD.norm_code OR NEW.source_refs IS DISTINCT FROM OLD.source_refs) THEN
    RAISE EXCEPTION 'reviewed training example content is immutable';
  END IF;
  RETURN NEW;
END $$;
SQL);
    }
};
