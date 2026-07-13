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
        DB::statement("SET lock_timeout = '5s'");
        DB::statement("SET statement_timeout = '15min'");
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN processing_lease_expires_at timestamptz');
        DB::statement('ALTER TABLE estimate_generation_training_datasets ADD COLUMN processing_attempt integer NOT NULL DEFAULT 0');
        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $runtime->ensureConcurrentIndex('eg_training_processing_lease_idx', 'CREATE INDEX CONCURRENTLY eg_training_processing_lease_idx ON estimate_generation_training_datasets (status, processing_lease_expires_at)');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT eg_training_processing_token_chk');
        $runtime->backfillProcessingLeases();
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_processing_lease_chk CHECK ((status = 'processing' AND processing_token IS NOT NULL AND processing_lease_expires_at IS NOT NULL AND processing_attempt > 0) OR (status <> 'processing' AND processing_token IS NULL AND processing_lease_expires_at IS NULL)) NOT VALID");
        DB::statement('ALTER TABLE estimate_generation_training_datasets VALIDATE CONSTRAINT eg_training_processing_lease_chk');
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_approval_pair_chk CHECK ((status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL) OR status <> 'approved') NOT VALID");
        DB::statement('ALTER TABLE estimate_generation_training_datasets VALIDATE CONSTRAINT eg_training_approval_pair_chk');

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
