<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("SET lock_timeout = '5s'");
        DB::statement("SET statement_timeout = '15min'");
        Schema::table('estimate_generation_training_datasets', function (Blueprint $table): void {
            $table->uuid('dataset_key')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->string('dataset_type', 20)->nullable();
            $table->string('scope', 20)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
        });
        Schema::table('estimate_generation_training_examples', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('system_admins')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
        });

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $runtime->backfillDatasets();
        $runtime->backfillExamples();
        foreach (['dataset_key', 'version', 'dataset_type', 'scope'] as $column) {
            $constraint = 'eg_training_'.str_replace('dataset_', '', $column).'_nn';
            DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT {$constraint} CHECK ({$column} IS NOT NULL) NOT VALID");
            DB::statement("ALTER TABLE estimate_generation_training_datasets VALIDATE CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE estimate_generation_training_datasets ALTER COLUMN {$column} SET NOT NULL");
            DB::statement("ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT {$constraint}");
        }
        DB::statement("ALTER TABLE estimate_generation_training_datasets ADD CONSTRAINT eg_training_dataset_type_chk CHECK (dataset_type IN ('development','regression','acceptance')), ADD CONSTRAINT eg_training_dataset_status_chk CHECK (status IN ('draft','processing','review_required','approved','rejected','archived')), ADD CONSTRAINT eg_training_dataset_scope_chk CHECK (scope = 'organization' AND organization_id IS NOT NULL), ADD CONSTRAINT eg_training_dataset_approval_chk CHECK (status <> 'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))");
        $runtime->ensureConcurrentIndex('eg_training_dataset_key_version_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_training_dataset_key_version_uq ON estimate_generation_training_datasets (organization_id, dataset_key, version)');
        $runtime->ensureConcurrentIndex('eg_training_dataset_id_version_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_training_dataset_id_version_uq ON estimate_generation_training_datasets (id, version)');
        $runtime->ensureConcurrentIndex('eg_training_dataset_trust_idx', 'CREATE INDEX CONCURRENTLY eg_training_dataset_trust_idx ON estimate_generation_training_datasets (organization_id, dataset_type, status, version)');
        DB::statement("ALTER TABLE estimate_generation_training_examples ADD CONSTRAINT eg_training_example_review_chk CHECK (status NOT IN ('accepted','indexed') OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");

        Schema::create('estimate_generation_benchmark_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key', 128);
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('training_dataset_id')->constrained('estimate_generation_training_datasets')->restrictOnDelete();
            $table->unsignedInteger('dataset_version');
            $table->string('pipeline_version', 100);
            $table->jsonb('model_versions');
            $table->string('normative_version', 100);
            $table->string('price_version', 100);
            $table->jsonb('metrics')->nullable();
            $table->jsonb('case_results')->nullable();
            $table->string('case_results_storage_disk', 20)->nullable();
            $table->text('case_results_storage_path')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->decimal('cost_amount', 20, 8)->default(0);
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('failure_code', 100)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key'], 'eg_benchmark_run_idempotency_uq');
            $table->index(['training_dataset_id', 'dataset_version'], 'eg_benchmark_run_dataset_idx');
        });
        DB::statement("ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_status_chk CHECK (status IN ('running','completed','failed')), ADD CONSTRAINT eg_benchmark_terminal_chk CHECK ((status = 'running' AND completed_at IS NULL) OR (status IN ('completed','failed') AND completed_at IS NOT NULL)), ADD CONSTRAINT eg_benchmark_results_chk CHECK (NOT (case_results IS NOT NULL AND case_results_storage_path IS NOT NULL)), ADD CONSTRAINT eg_benchmark_s3_chk CHECK (case_results_storage_path IS NULL OR case_results_storage_disk = 's3'), ADD CONSTRAINT eg_benchmark_json_bounds_chk CHECK (pg_column_size(model_versions) <= 1048576 AND (metrics IS NULL OR pg_column_size(metrics) <= 1048576) AND (case_results IS NULL OR pg_column_size(case_results) <= 1048576)), ADD CONSTRAINT eg_benchmark_cost_chk CHECK (cost_amount >= 0 AND currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_dataset_version_fk FOREIGN KEY (training_dataset_id, dataset_version) REFERENCES estimate_generation_training_datasets (id, version) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_guard_training_dataset_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status IN ('approved','archived') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'immutable training dataset'; END IF;
  IF NEW.version <> OLD.version OR NEW.dataset_key <> OLD.dataset_key OR NEW.organization_id <> OLD.organization_id OR NEW.dataset_type <> OLD.dataset_type THEN RAISE EXCEPTION 'immutable dataset identity'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_training_dataset_immutable BEFORE UPDATE ON estimate_generation_training_datasets FOR EACH ROW EXECUTE FUNCTION eg_guard_training_dataset_immutable();
CREATE FUNCTION eg_guard_training_example_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dataset_status text;
BEGIN
  SELECT status INTO dataset_status FROM estimate_generation_training_datasets WHERE id = CASE WHEN TG_OP = 'INSERT' THEN NEW.training_dataset_id ELSE OLD.training_dataset_id END;
  IF TG_OP = 'INSERT' THEN
    IF dataset_status IN ('approved','archived') THEN RAISE EXCEPTION 'immutable training dataset membership'; END IF;
    RETURN NEW;
  END IF;
  IF TG_OP = 'DELETE' THEN
    IF dataset_status IN ('approved','archived') THEN RAISE EXCEPTION 'immutable training example'; END IF;
    RETURN OLD;
  END IF;
  IF dataset_status IN ('approved','archived') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'immutable training example'; END IF;
  IF TG_OP = 'UPDATE' AND NEW.training_dataset_id <> OLD.training_dataset_id THEN RAISE EXCEPTION 'immutable dataset membership'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_training_example_immutable BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_training_examples FOR EACH ROW EXECUTE FUNCTION eg_guard_training_example_immutable();
CREATE FUNCTION eg_validate_benchmark_dataset() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dataset_row estimate_generation_training_datasets%ROWTYPE;
BEGIN
  SELECT * INTO dataset_row FROM estimate_generation_training_datasets WHERE id = NEW.training_dataset_id;
  IF dataset_row.organization_id <> NEW.organization_id OR dataset_row.version <> NEW.dataset_version OR dataset_row.status <> 'approved' THEN RAISE EXCEPTION 'invalid benchmark dataset scope or version'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_benchmark_dataset_scope BEFORE INSERT ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION eg_validate_benchmark_dataset();
CREATE FUNCTION eg_guard_benchmark_run_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF OLD.status <> 'running' OR NEW.status NOT IN ('completed','failed') THEN RAISE EXCEPTION 'immutable benchmark run'; END IF;
  IF NEW.uuid <> OLD.uuid OR NEW.organization_id <> OLD.organization_id OR NEW.training_dataset_id <> OLD.training_dataset_id OR NEW.dataset_version <> OLD.dataset_version OR NEW.pipeline_version <> OLD.pipeline_version OR NEW.model_versions <> OLD.model_versions OR NEW.normative_version <> OLD.normative_version OR NEW.price_version <> OLD.price_version OR NEW.started_at <> OLD.started_at THEN RAISE EXCEPTION 'immutable benchmark manifest'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_benchmark_run_immutable BEFORE UPDATE OR DELETE ON estimate_generation_benchmark_runs FOR EACH ROW EXECUTE FUNCTION eg_guard_benchmark_run_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS eg_benchmark_run_immutable ON estimate_generation_benchmark_runs; DROP FUNCTION IF EXISTS eg_guard_benchmark_run_immutable(); DROP TRIGGER IF EXISTS eg_benchmark_dataset_scope ON estimate_generation_benchmark_runs; DROP FUNCTION IF EXISTS eg_validate_benchmark_dataset(); DROP TRIGGER IF EXISTS eg_training_example_immutable ON estimate_generation_training_examples; DROP FUNCTION IF EXISTS eg_guard_training_example_immutable(); DROP TRIGGER IF EXISTS eg_training_dataset_immutable ON estimate_generation_training_datasets; DROP FUNCTION IF EXISTS eg_guard_training_dataset_immutable();');
        Schema::dropIfExists('estimate_generation_benchmark_runs');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS eg_training_example_review_chk');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP CONSTRAINT IF EXISTS estimate_generation_training_examples_reviewed_by_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_examples DROP COLUMN IF EXISTS reviewed_by, DROP COLUMN IF EXISTS reviewed_at');
        DB::statement('DROP INDEX IF EXISTS eg_training_dataset_id_version_uq');
        DB::statement('DROP INDEX IF EXISTS eg_training_dataset_key_version_uq');
        DB::statement('DROP INDEX IF EXISTS eg_training_dataset_trust_idx');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS eg_training_dataset_type_chk, DROP CONSTRAINT IF EXISTS eg_training_dataset_status_chk, DROP CONSTRAINT IF EXISTS eg_training_dataset_scope_chk, DROP CONSTRAINT IF EXISTS eg_training_dataset_approval_chk');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP CONSTRAINT IF EXISTS estimate_generation_training_datasets_approved_by_foreign');
        DB::statement('ALTER TABLE estimate_generation_training_datasets DROP COLUMN IF EXISTS approved_by, DROP COLUMN IF EXISTS dataset_key, DROP COLUMN IF EXISTS version, DROP COLUMN IF EXISTS dataset_type, DROP COLUMN IF EXISTS scope, DROP COLUMN IF EXISTS approved_at');
    }
};
