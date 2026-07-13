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
            DB::statement('ALTER TABLE estimate_generation_training_examples ADD COLUMN IF NOT EXISTS organization_id bigint, ADD COLUMN IF NOT EXISTS dataset_version integer');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_training_membership_write_fence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dataset_row estimate_generation_training_datasets%ROWTYPE;
BEGIN
  SELECT * INTO STRICT dataset_row FROM estimate_generation_training_datasets WHERE id = NEW.training_dataset_id;
  NEW.organization_id := dataset_row.organization_id;
  NEW.dataset_version := dataset_row.version;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_training_membership_write_fence ON estimate_generation_training_examples;
CREATE TRIGGER eg_training_membership_write_fence BEFORE INSERT OR UPDATE OF training_dataset_id, organization_id, dataset_version ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_training_membership_write_fence();
SQL);
            $runtime->checkpoint('001800_structure');
            $runtime->backfillMembership();
            $runtime->checkpoint('001800_backfill');
            foreach (['organization_id', 'dataset_version'] as $column) {
                $constraint = "eg_training_example_{$column}_nn";
                $runtime->ensureConstraint('estimate_generation_training_examples', $constraint, "CHECK ({$column} IS NOT NULL)");
                $runtime->validateConstraint('estimate_generation_training_examples', $constraint);
                DB::statement("ALTER TABLE estimate_generation_training_examples ALTER COLUMN {$column} SET NOT NULL");
                DB::statement("ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT {$constraint}");
            }
            $runtime->ensureConcurrentIndex('eg_training_dataset_membership_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_training_dataset_membership_uq ON estimate_generation_training_datasets (id, organization_id, version)');
            $runtime->checkpoint('001800_indexes');
            $runtime->swapValidatedConstraint('estimate_generation_training_files', 'estimate_generation_training_files_training_dataset_id_foreign', 'eg_training_files_dataset_restrict_fk', 'FOREIGN KEY (training_dataset_id) REFERENCES estimate_generation_training_datasets(id) ON DELETE RESTRICT');
            $runtime->ensureConstraint('estimate_generation_training_examples', 'eg_training_example_membership_fk', 'FOREIGN KEY (training_dataset_id, organization_id, dataset_version) REFERENCES estimate_generation_training_datasets(id, organization_id, version) ON DELETE RESTRICT');
            $runtime->validateConstraint('estimate_generation_training_examples', 'eg_training_example_membership_fk');
            DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS estimate_generation_training_examples_training_dataset_id_foreign');
            $runtime->swapValidatedConstraint('estimate_generation_training_datasets', 'estimate_generation_training_datasets_organization_id_foreign', 'eg_training_dataset_organization_restrict_fk', 'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT');

            $runtime->swapValidatedConstraint('estimate_generation_benchmark_runs', 'eg_benchmark_closed_state_chk', 'eg_benchmark_closed_state_001800_chk', "CHECK (
            (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_path IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND cost_amount = 0)
            OR (status = 'completed' AND completed_at IS NOT NULL AND completed_at >= started_at AND metrics IS NOT NULL AND jsonb_typeof(metrics) = 'object' AND metrics <> '{}'::jsonb AND duration_ms IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND ((case_results IS NOT NULL AND case_results_storage_path IS NULL) OR (case_results IS NULL AND case_results_storage_path IS NOT NULL)))
            OR (status = 'failed' AND completed_at IS NOT NULL AND completed_at >= started_at AND failure_code IS NOT NULL AND length(btrim(failure_code)) BETWEEN 1 AND 100 AND metrics IS NULL AND case_results IS NULL AND case_results_storage_path IS NULL AND duration_ms IS NULL AND cost_amount = 0)
        )");
            DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_terminal_chk, DROP CONSTRAINT IF EXISTS eg_benchmark_results_chk');
            $runtime->checkpoint('001800_constraints');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_training_dataset_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' AND OLD.status IN ('approved','archived') THEN RAISE EXCEPTION 'immutable training dataset'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  IF OLD.status = 'approved' AND NEW.status = 'archived' THEN
    IF (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at') THEN RAISE EXCEPTION 'archive transition may only change status'; END IF;
    RETURN NEW;
  END IF;
  IF OLD.status IN ('approved','archived') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'immutable training dataset'; END IF;
  IF NEW.version <> OLD.version OR NEW.dataset_key <> OLD.dataset_key OR NEW.organization_id <> OLD.organization_id OR NEW.dataset_type <> OLD.dataset_type THEN RAISE EXCEPTION 'immutable dataset identity'; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_training_dataset_immutable ON estimate_generation_training_datasets;
CREATE TRIGGER eg_training_dataset_immutable BEFORE UPDATE OR DELETE ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_guard_training_dataset_immutable();

CREATE OR REPLACE FUNCTION eg_guard_benchmark_run_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF OLD.status <> 'running' OR NEW.status NOT IN ('completed','failed') THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF NEW.uuid <> OLD.uuid OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.organization_id <> OLD.organization_id OR NEW.training_dataset_id <> OLD.training_dataset_id OR NEW.dataset_version <> OLD.dataset_version OR NEW.pipeline_version <> OLD.pipeline_version OR NEW.model_versions <> OLD.model_versions OR NEW.normative_version <> OLD.normative_version OR NEW.price_version <> OLD.price_version OR NEW.currency <> OLD.currency OR NEW.started_at <> OLD.started_at THEN RAISE EXCEPTION 'immutable benchmark manifest'; END IF;
  RETURN NEW;
END $$;
SQL);
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('estimate_generation_benchmark_runs')) {
            DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_closed_state_chk');
            DB::statement("ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_terminal_chk CHECK ((status = 'running' AND completed_at IS NULL) OR (status IN ('completed','failed') AND completed_at IS NOT NULL)), ADD CONSTRAINT eg_benchmark_results_chk CHECK (NOT (case_results IS NOT NULL AND case_results_storage_path IS NOT NULL))");
        }
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS eg_training_example_membership_fk');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS estimate_generation_training_examples_training_dataset_id_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_examples ADD CONSTRAINT estimate_generation_training_examples_training_dataset_id_foreign FOREIGN KEY (training_dataset_id) REFERENCES estimate_generation_training_datasets(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE estimate_generation_training_files DROP CONSTRAINT IF EXISTS estimate_generation_training_files_training_dataset_id_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_files ADD CONSTRAINT estimate_generation_training_files_training_dataset_id_foreign FOREIGN KEY (training_dataset_id) REFERENCES estimate_generation_training_datasets(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS estimate_generation_training_datasets_organization_id_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT estimate_generation_training_datasets_organization_id_foreign FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE');
        DB::statement('DROP INDEX IF EXISTS eg_training_dataset_membership_uq');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP COLUMN IF EXISTS organization_id, DROP COLUMN IF EXISTS dataset_version');
    }
};
