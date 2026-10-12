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
        Schema::create('estimate_generation_qa_expense_runs', function (Blueprint $table): void {
            $table->uuid('run_id')->primary();
            $table->unsignedBigInteger('maximum_units_minor');
            $table->unsignedBigInteger('unit_cost_micro_rub');
            $table->unsignedInteger('unit_minor');
            $table->unsignedBigInteger('maximum_cost_micro_rub');
            $table->unsignedInteger('price_version');
            $table->string('status', 20)->default('active');
            $table->timestampTz('created_at');
        });
        Schema::create('estimate_generation_qa_expense_attempts', function (Blueprint $table): void {
            $table->uuid('attempt_id')->primary();
            $table->foreign('attempt_id')->references('attempt_id')->on('estimate_generation_vision_physical_attempts')->restrictOnDelete();
            $table->uuid('run_id');
            $table->foreign('run_id')->references('run_id')->on('estimate_generation_qa_expense_runs')->restrictOnDelete();
            $table->unsignedBigInteger('maximum_cost_micro_rub');
            $table->timestampTz('created_at');
            $table->index('run_id');
        });
        DB::statement("ALTER TABLE estimate_generation_qa_expense_runs ADD CONSTRAINT estimator_qa_run_valid CHECK
            (maximum_units_minor BETWEEN 1 AND 50000 AND unit_cost_micro_rub > 0 AND unit_minor > 0
                AND maximum_cost_micro_rub > 0 AND price_version > 0 AND status IN ('active','completed'))");
        DB::statement('ALTER TABLE estimate_generation_qa_expense_attempts ADD CONSTRAINT estimator_qa_attempt_valid CHECK (maximum_cost_micro_rub > 0)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_qa_expense_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'estimate_generation.qa_expense_history_is_immutable'; END IF;
  IF TG_TABLE_NAME='estimate_generation_qa_expense_attempts' AND TG_OP='UPDATE'
    THEN RAISE EXCEPTION 'estimate_generation.qa_expense_attempt_is_immutable'; END IF;
  IF TG_TABLE_NAME='estimate_generation_qa_expense_runs' AND TG_OP='UPDATE'
    AND (ROW(OLD.run_id,OLD.maximum_units_minor,OLD.unit_cost_micro_rub,OLD.unit_minor,OLD.maximum_cost_micro_rub,OLD.price_version,OLD.created_at)
      IS DISTINCT FROM ROW(NEW.run_id,NEW.maximum_units_minor,NEW.unit_cost_micro_rub,NEW.unit_minor,NEW.maximum_cost_micro_rub,NEW.price_version,NEW.created_at)
      OR OLD.status='completed' AND NEW.status <> OLD.status)
    THEN RAISE EXCEPTION 'estimate_generation.qa_expense_run_is_immutable'; END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER eg_qa_expense_run_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_qa_expense_runs
  FOR EACH ROW EXECUTE FUNCTION eg_qa_expense_identity_guard();
CREATE TRIGGER eg_qa_expense_attempt_guard_trg BEFORE UPDATE OR DELETE ON estimate_generation_qa_expense_attempts
  FOR EACH ROW EXECUTE FUNCTION eg_qa_expense_identity_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_qa_expense_attempts');
        Schema::dropIfExists('estimate_generation_qa_expense_runs');
        DB::statement('DROP FUNCTION IF EXISTS eg_qa_expense_identity_guard()');
    }
};
