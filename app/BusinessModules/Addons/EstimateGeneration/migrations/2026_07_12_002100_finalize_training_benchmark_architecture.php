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
            DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS processing_lease_expires_at timestamptz');
            $runtime->checkpoint('002100.column.processing_lease_expires_at.adopted');
            DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN IF NOT EXISTS processing_attempt integer NOT NULL DEFAULT 0');
            $runtime->checkpoint('002100.column.processing_attempt.adopted');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_training_lease_write_fence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status = 'processing' THEN
    NEW.processing_token := COALESCE(NEW.processing_token, gen_random_uuid());
    NEW.processing_lease_expires_at := COALESCE(NEW.processing_lease_expires_at, CURRENT_TIMESTAMP + INTERVAL '15 minutes');
    NEW.processing_attempt := GREATEST(NEW.processing_attempt, 1);
  ELSE
    NEW.processing_token := NULL;
    NEW.processing_lease_expires_at := NULL;
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_training_lease_write_fence ON estimate_generation_training_datasets;
CREATE TRIGGER eg_training_lease_write_fence BEFORE INSERT OR UPDATE OF status, processing_token, processing_lease_expires_at, processing_attempt ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_training_lease_write_fence();
SQL);
            $runtime->checkpoint('002100_structure');
            $runtime->ensureConcurrentIndex('eg_training_processing_lease_idx', 'CREATE INDEX CONCURRENTLY eg_training_processing_lease_idx ON estimate_generation_training_datasets (status, processing_lease_expires_at)');
            $runtime->backfillProcessingLeases();
            $runtime->checkpoint('002100_backfill');
            $runtime->ensureConstraint('estimate_generation_training_datasets', 'eg_training_processing_lease_chk', "CHECK ((status = 'processing' AND processing_token IS NOT NULL AND processing_lease_expires_at IS NOT NULL AND processing_attempt > 0) OR (status <> 'processing' AND processing_token IS NULL AND processing_lease_expires_at IS NULL))");
            $runtime->validateConstraint('estimate_generation_training_datasets', 'eg_training_processing_lease_chk');
            DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_processing_token_chk');
            $runtime->ensureConstraint('estimate_generation_training_datasets', 'eg_training_approval_pair_chk', "CHECK ((status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL) OR status <> 'approved')");
            $runtime->validateConstraint('estimate_generation_training_datasets', 'eg_training_approval_pair_chk');
            $runtime->checkpoint('002100_constraints');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_guard_training_dataset_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status = 'approved' AND (
    NEW.approved_by IS NULL OR NEW.approved_at IS NULL OR
    NOT EXISTS (SELECT 1 FROM estimate_generation_training_examples e WHERE e.training_dataset_id = NEW.id) OR
    EXISTS (SELECT 1 FROM estimate_generation_training_examples e WHERE e.training_dataset_id = NEW.id AND (e.status <> 'accepted' OR e.reviewed_by IS NULL OR e.reviewed_at IS NULL))
  ) THEN
    RAISE EXCEPTION 'approved dataset requires a nonempty fully accepted and reviewed corpus';
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_training_dataset_approval_guard ON estimate_generation_training_datasets;
CREATE CONSTRAINT TRIGGER eg_training_dataset_approval_guard AFTER INSERT OR UPDATE ON estimate_generation_training_datasets DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION eg_guard_training_dataset_approval();

CREATE OR REPLACE FUNCTION eg_guard_example_for_approved_dataset() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM estimate_generation_training_datasets d WHERE d.id = COALESCE(NEW.training_dataset_id, OLD.training_dataset_id) AND d.status = 'approved') THEN
    RAISE EXCEPTION 'examples of approved dataset are immutable';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_approved_dataset_example_guard ON estimate_generation_training_examples;
CREATE TRIGGER eg_approved_dataset_example_guard BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_guard_example_for_approved_dataset();
SQL);
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS eg_approved_dataset_example_guard ON estimate_generation_training_examples; DROP FUNCTION IF EXISTS eg_guard_example_for_approved_dataset(); DROP TRIGGER IF EXISTS eg_training_dataset_approval_guard ON estimate_generation_training_datasets; DROP FUNCTION IF EXISTS eg_guard_training_dataset_approval();');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_approval_pair_chk');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_processing_lease_chk');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_processing_token_chk CHECK ((status = 'processing' AND processing_token IS NULL OR processing_token IS NOT NULL) OR (status <> 'processing' AND processing_token IS NULL))");
        DB::statement('DROP INDEX IF EXISTS eg_training_processing_lease_idx');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_attempt');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS processing_lease_expires_at');
    }
};
