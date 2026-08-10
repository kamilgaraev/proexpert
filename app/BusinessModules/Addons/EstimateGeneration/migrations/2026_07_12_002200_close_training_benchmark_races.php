<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_training_dataset_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status = 'approved' THEN
    PERFORM pg_advisory_xact_lock(NEW.id);
    IF NEW.approved_by IS NULL OR NEW.approved_at IS NULL OR
       NOT EXISTS (SELECT 1 FROM estimate_generation_training_examples e WHERE e.training_dataset_id = NEW.id) OR
       EXISTS (SELECT 1 FROM estimate_generation_training_examples e WHERE e.training_dataset_id = NEW.id AND (e.status <> 'accepted' OR e.reviewed_by IS NULL OR e.reviewed_at IS NULL)) THEN
      RAISE EXCEPTION 'approved dataset requires a nonempty fully accepted and reviewed corpus';
    END IF;
  END IF;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION eg_guard_example_for_approved_dataset() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dataset_id bigint;
BEGIN
  dataset_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.training_dataset_id ELSE NEW.training_dataset_id END;
  PERFORM pg_advisory_xact_lock(dataset_id);
  IF EXISTS (SELECT 1 FROM estimate_generation_training_datasets d WHERE d.id = dataset_id AND d.status IN ('approved', 'archived')) THEN
    RAISE EXCEPTION 'examples of approved dataset are immutable';
  END IF;
  IF TG_OP = 'UPDATE' AND OLD.training_dataset_id <> NEW.training_dataset_id THEN
    PERFORM pg_advisory_xact_lock(LEAST(OLD.training_dataset_id, NEW.training_dataset_id));
    PERFORM pg_advisory_xact_lock(GREATEST(OLD.training_dataset_id, NEW.training_dataset_id));
    IF EXISTS (SELECT 1 FROM estimate_generation_training_datasets d WHERE d.id IN (OLD.training_dataset_id, NEW.training_dataset_id) AND d.status IN ('approved', 'archived')) THEN
      RAISE EXCEPTION 'examples of approved dataset are immutable';
    END IF;
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
SQL);
            $runtime->checkpoint('002200_structure');

            $runtime->swapValidatedConstraint('estimate_generation_benchmark_runs', 'eg_benchmark_closed_state_chk', 'eg_benchmark_closed_state_002200_chk', <<<'SQL'
CHECK (
 (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND error_summary IS NULL AND cost_amount = 0)
 OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND error_summary IS NULL AND cost_amount >= 0 AND currency ~ '^[A-Z]{3}$' AND (
   (case_results IS NOT NULL AND jsonb_typeof(case_results) = 'array' AND case_results <> '[]'::jsonb AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL)
   OR (case_results IS NULL AND case_results_storage_disk = 's3' AND case_results_storage_path ~ ('^org-' || organization_id::text || '/estimate-generation/benchmarks/' || uuid::text || '/[a-f0-9]{64}\.json$') AND case_results_size > 0 AND case_results_size <= 64000000 AND case_results_sha256 ~ '^[a-f0-9]{64}$' AND case_results_storage_path LIKE ('%/' || case_results_sha256 || '.json') AND case_results_content_type = 'application/json' AND case_results_version IS NOT NULL AND btrim(case_results_version) <> '' AND case_results_version_scheme = 'provider+sha256')
 ))
 OR (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND length(btrim(failure_code)) BETWEEN 1 AND 100 AND error_summary IS NOT NULL AND length(btrim(error_summary)) BETWEEN 1 AND 500 AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_version IS NULL AND case_results_version_scheme IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND cost_amount = 0)
)
SQL);
            $runtime->checkpoint('002200_constraints');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('estimate_generation_training_benchmark_migration_is_forward_only');
    }
};
