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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Estimator credit operations require PostgreSQL.');
        }
        Schema::create('estimate_generation_credit_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->unsignedBigInteger('approved_state_version');
            $table->char('input_hash', 64);
            $table->string('source_version', 71);
            $table->char('manifest_hash', 64);
            $table->char('plan_hash', 64);
            $table->jsonb('processing_plan');
            $table->jsonb('credit_request');
            $table->foreignId('quote_id')->constrained('ai_credit_quotes')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('ai_credit_reservations')->restrictOnDelete();
            $table->unsignedBigInteger('maximum_provider_cost_micro_rub');
            $table->unsignedInteger('maximum_calls');
            $table->unsignedBigInteger('consumed_units_minor')->default(0);
            $table->uuid('result_revision_id')->nullable();
            $table->jsonb('receipt')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'session_id', 'status']);
        });
        DB::statement("ALTER TABLE estimate_generation_credit_operations ADD CONSTRAINT estimator_credit_operation_valid CHECK
            (kind IN ('generation','analysis','dialogue','documents','rebuild') AND status IN ('quoted','reserved','running','settled','cancelled','failed')
             AND maximum_provider_cost_micro_rub > 0 AND maximum_calls BETWEEN 1 AND 10000
             AND input_hash ~ '^[a-f0-9]{64}$' AND manifest_hash ~ '^[a-f0-9]{64}$' AND plan_hash ~ '^[a-f0-9]{64}$'
             AND source_version ~ '^sha256:[a-f0-9]{64}$' AND jsonb_typeof(processing_plan)='object' AND jsonb_typeof(credit_request)='object'
             AND (status='quoted' OR reservation_id IS NOT NULL))");
        Schema::create('estimate_generation_credit_attempts', function (Blueprint $table): void {
            $table->uuid('attempt_id')->primary();
            $table->uuid('operation_id');
            $table->foreign('operation_id')->references('operation_id')->on('estimate_generation_credit_operations')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->restrictOnDelete();
            $table->foreignId('reservation_id')->constrained('ai_credit_reservations')->restrictOnDelete();
            $table->string('role', 80);
            $table->unsignedBigInteger('maximum_cost_micro_rub');
            $table->string('status', 20)->default('admitted');
            $table->char('usage_fingerprint', 64)->nullable();
            $table->timestampTz('usage_transferred_at')->nullable();
            $table->timestampsTz();
            $table->index(['operation_id', 'status']);
        });
        DB::statement("ALTER TABLE estimate_generation_credit_attempts ADD CONSTRAINT estimator_credit_attempt_valid CHECK
            (maximum_cost_micro_rub > 0 AND status IN ('admitted','recorded','unavailable')
             AND (usage_fingerprint IS NULL OR usage_fingerprint ~ '^[a-f0-9]{64}$'))");
        Schema::table('estimate_generation_vision_physical_attempts', function (Blueprint $table): void {
            $table->uuid('credit_operation_id')->nullable();
            $table->foreign('credit_operation_id')->references('operation_id')->on('estimate_generation_credit_operations')->restrictOnDelete();
            $table->string('credit_role', 80)->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_credit_operation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'estimate_generation.credit_history_is_immutable'; END IF;
  IF TG_OP='UPDATE' AND (ROW(OLD.operation_id,OLD.organization_id,OLD.project_id,OLD.session_id,OLD.actor_user_id,
      OLD.kind,OLD.approved_state_version,OLD.input_hash,OLD.source_version,OLD.manifest_hash,OLD.plan_hash,
      OLD.processing_plan,OLD.credit_request,OLD.quote_id,OLD.maximum_provider_cost_micro_rub,OLD.maximum_calls,OLD.created_at)
    IS DISTINCT FROM ROW(NEW.operation_id,NEW.organization_id,NEW.project_id,NEW.session_id,NEW.actor_user_id,
      NEW.kind,NEW.approved_state_version,NEW.input_hash,NEW.source_version,NEW.manifest_hash,NEW.plan_hash,
      NEW.processing_plan,NEW.credit_request,NEW.quote_id,NEW.maximum_provider_cost_micro_rub,NEW.maximum_calls,NEW.created_at)
    OR (OLD.reservation_id IS NOT NULL AND OLD.reservation_id IS DISTINCT FROM NEW.reservation_id)
    OR (OLD.status IN ('settled','cancelled','failed') AND OLD.status IS DISTINCT FROM NEW.status))
  THEN RAISE EXCEPTION 'estimate_generation.credit_operation_identity_is_immutable'; END IF;
  IF NOT EXISTS (SELECT 1 FROM estimate_generation_sessions s JOIN projects p ON p.id=s.project_id
      JOIN ai_credit_quotes q ON q.id=NEW.quote_id
      WHERE s.id=NEW.session_id AND s.organization_id=NEW.organization_id AND s.project_id=NEW.project_id
        AND p.organization_id=NEW.organization_id AND q.organization_id=NEW.organization_id
        AND q.user_id=NEW.actor_user_id AND q.profile='estimator_luna:v1')
    OR (NEW.reservation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ai_credit_reservations r
      WHERE r.id=NEW.reservation_id AND r.organization_id=NEW.organization_id AND r.user_id=NEW.actor_user_id
        AND r.ai_credit_quote_id=NEW.quote_id))
  THEN RAISE EXCEPTION 'estimate_generation.credit_operation_scope_mismatch'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_credit_operation_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_credit_operations
FOR EACH ROW EXECUTE FUNCTION eg_credit_operation_guard();
CREATE FUNCTION eg_credit_attempt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'estimate_generation.credit_history_is_immutable'; END IF;
  IF TG_OP='UPDATE' AND (ROW(OLD.attempt_id,OLD.operation_id,OLD.organization_id,OLD.project_id,OLD.session_id,
      OLD.reservation_id,OLD.role,OLD.maximum_cost_micro_rub,OLD.created_at)
    IS DISTINCT FROM ROW(NEW.attempt_id,NEW.operation_id,NEW.organization_id,NEW.project_id,NEW.session_id,
      NEW.reservation_id,NEW.role,NEW.maximum_cost_micro_rub,NEW.created_at)
    OR (OLD.usage_fingerprint IS NOT NULL AND OLD.usage_fingerprint IS DISTINCT FROM NEW.usage_fingerprint))
  THEN RAISE EXCEPTION 'estimate_generation.credit_attempt_identity_is_immutable'; END IF;
  IF NOT EXISTS (SELECT 1 FROM estimate_generation_credit_operations o WHERE o.operation_id=NEW.operation_id
      AND o.organization_id=NEW.organization_id AND o.project_id=NEW.project_id AND o.session_id=NEW.session_id
      AND o.reservation_id=NEW.reservation_id)
  THEN RAISE EXCEPTION 'estimate_generation.credit_attempt_scope_mismatch'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_credit_attempt_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_credit_attempts
FOR EACH ROW EXECUTE FUNCTION eg_credit_attempt_guard();
CREATE FUNCTION eg_physical_credit_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND ROW(OLD.credit_operation_id,OLD.credit_role) IS DISTINCT FROM ROW(NEW.credit_operation_id,NEW.credit_role)
  THEN RAISE EXCEPTION 'estimate_generation.physical_credit_identity_is_immutable'; END IF;
  IF (NEW.credit_operation_id IS NULL) <> (NEW.credit_role IS NULL) OR (NEW.credit_operation_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM estimate_generation_credit_operations o WHERE o.operation_id=NEW.credit_operation_id
      AND o.organization_id=NEW.organization_id AND o.project_id=NEW.project_id AND o.session_id=NEW.session_id))
  THEN RAISE EXCEPTION 'estimate_generation.physical_credit_scope_mismatch'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER eg_physical_credit_identity_guard_trg BEFORE INSERT OR UPDATE ON estimate_generation_vision_physical_attempts
FOR EACH ROW EXECUTE FUNCTION eg_physical_credit_identity_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Estimator financial history is forward-only; stop new operations for rollback.');
    }
};
