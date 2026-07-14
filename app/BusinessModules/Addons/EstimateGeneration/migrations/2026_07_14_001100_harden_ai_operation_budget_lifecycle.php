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
            throw new RuntimeException('estimate_generation_ai_budget_lifecycle_requires_postgresql');
        }

        Schema::create('estimate_generation_ai_operations', function (Blueprint $table): void {
            $table->uuid('correlation_id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('global_setting_snapshot_id');
            $table->unsignedBigInteger('effective_setting_snapshot_id');
            $table->char('immutable_fingerprint', 71);
            $table->timestampTz('created_at');
            $table->foreign(['session_id', 'organization_id'], 'eg_ai_operation_session_fk')
                ->references(['id', 'organization_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign('global_setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
            $table->foreign('effective_setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
        });
        Schema::table('estimate_generation_ai_budget_reservations', function (Blueprint $table): void {
            $table->char('immutable_fingerprint', 71)->nullable();
            $table->timestampTz('expires_at')->nullable();
        });

        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations ALTER COLUMN status TYPE varchar(32)');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_status_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_state_ck');
        DB::statement(<<<'SQL'
UPDATE estimate_generation_ai_budget_reservations
SET status = CASE WHEN status = 'reserved' THEN 'authorized' ELSE status END,
    immutable_fingerprint = 'sha256:' || encode(pg_catalog.sha256(pg_catalog.convert_to(
        'pre-operation-pin|' || attempt_id::text || '|' || organization_id::text || '|' || session_id::text || '|'
        || global_setting_snapshot_id::text || '|' || organization_setting_snapshot_id::text || '|'
        || reserved_amount::text || '|' || currency || '|' || price_snapshot::text,
        'UTF8'
    )), 'hex'),
    expires_at = CASE WHEN status = 'reserved' THEN created_at + interval '5 minutes' ELSE settled_at END
SQL);
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations ALTER COLUMN immutable_fingerprint SET NOT NULL');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations ALTER COLUMN expires_at SET NOT NULL');
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_status_ck CHECK (status IN ('authorized','sent_pending','reconciliation_required','settled','released'))");
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_fingerprint_ck CHECK (immutable_fingerprint ~ '^sha256:[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_state_ck CHECK ((status IN ('authorized','sent_pending','reconciliation_required') AND actual_amount IS NULL AND settled_at IS NULL) OR (status = 'settled' AND actual_amount IS NOT NULL AND settled_at IS NOT NULL) OR (status = 'released' AND actual_amount = 0 AND settled_at IS NOT NULL))");

        DB::statement('DROP FUNCTION IF EXISTS eg_reserve_ai_budget(uuid, bigint, bigint, bigint, bigint, numeric, text, jsonb)');
        DB::statement('DROP FUNCTION IF EXISTS eg_settle_ai_budget(uuid, numeric, text)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_pin_ai_operation_settings(p_correlation uuid, p_organization bigint, p_session bigint)
RETURNS TABLE(global_snapshot_id bigint, effective_snapshot_id bigint) LANGUAGE plpgsql AS $$
DECLARE v_existing estimate_generation_ai_operations%ROWTYPE; v_global bigint; v_effective bigint; v_fingerprint text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-operation:' || p_correlation::text, 0));
  SELECT * INTO v_existing FROM estimate_generation_ai_operations WHERE correlation_id = p_correlation;
  IF FOUND THEN
    IF v_existing.organization_id <> p_organization OR v_existing.session_id <> p_session THEN
      RAISE EXCEPTION 'estimate_generation_ai_operation_identity_conflict';
    END IF;
    RETURN QUERY SELECT v_existing.global_setting_snapshot_id, v_existing.effective_setting_snapshot_id;
    RETURN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM estimate_generation_sessions WHERE id = p_session AND organization_id = p_organization) THEN
    RAISE EXCEPTION 'estimate_generation_ai_operation_scope_invalid';
  END IF;
  SELECT id INTO STRICT v_global FROM estimate_generation_setting_snapshots
    WHERE scope = 'global' AND organization_id IS NULL ORDER BY version DESC LIMIT 1;
  SELECT id INTO v_effective FROM estimate_generation_setting_snapshots
    WHERE scope = 'organization' AND organization_id = p_organization ORDER BY version DESC LIMIT 1;
  v_effective := COALESCE(v_effective, v_global);
  v_fingerprint := 'sha256:' || encode(pg_catalog.sha256(pg_catalog.convert_to(
    p_correlation::text || '|' || p_organization::text || '|' || p_session::text || '|' || v_global::text || '|' || v_effective::text, 'UTF8')), 'hex');
  INSERT INTO estimate_generation_ai_operations
    (correlation_id, organization_id, session_id, global_setting_snapshot_id, effective_setting_snapshot_id, immutable_fingerprint, created_at)
  VALUES (p_correlation, p_organization, p_session, v_global, v_effective, v_fingerprint, now());
  RETURN QUERY SELECT v_global, v_effective;
END;
$$;

CREATE FUNCTION eg_reserve_ai_budget(
  p_attempt uuid, p_correlation uuid, p_organization bigint, p_session bigint,
  p_global_snapshot bigint, p_effective_snapshot bigint, p_amount numeric,
  p_currency text, p_price jsonb, p_fingerprint text
) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
  v_id uuid; v_existing estimate_generation_ai_budget_reservations%ROWTYPE;
  v_operation estimate_generation_ai_operations%ROWTYPE;
  v_global estimate_generation_setting_snapshots%ROWTYPE; v_organization estimate_generation_setting_snapshots%ROWTYPE;
  v_global_daily numeric; v_global_monthly numeric; v_org_daily numeric; v_org_monthly numeric;
BEGIN
  IF p_amount < 0 OR p_currency !~ '^[A-Z]{3}$' OR p_fingerprint !~ '^sha256:[a-f0-9]{64}$' THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_request_invalid';
  END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global', 0));
  PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-org:' || p_organization::text, 0));
  SELECT * INTO v_existing FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt;
  IF FOUND THEN
    IF v_existing.organization_id <> p_organization OR v_existing.session_id <> p_session
      OR v_existing.global_setting_snapshot_id <> p_global_snapshot
      OR v_existing.organization_setting_snapshot_id <> p_effective_snapshot
      OR v_existing.reserved_amount <> p_amount OR v_existing.currency <> p_currency
      OR v_existing.price_snapshot <> p_price OR v_existing.immutable_fingerprint <> p_fingerprint THEN
      RAISE EXCEPTION 'estimate_generation_ai_budget_attempt_conflict';
    END IF;
    RETURN v_existing.reservation_id;
  END IF;
  SELECT * INTO STRICT v_operation FROM estimate_generation_ai_operations WHERE correlation_id = p_correlation;
  IF v_operation.organization_id <> p_organization OR v_operation.session_id <> p_session
    OR v_operation.global_setting_snapshot_id <> p_global_snapshot
    OR v_operation.effective_setting_snapshot_id <> p_effective_snapshot THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_operation_mismatch';
  END IF;
  SELECT * INTO STRICT v_global FROM estimate_generation_setting_snapshots WHERE id = p_global_snapshot;
  SELECT * INTO STRICT v_organization FROM estimate_generation_setting_snapshots WHERE id = p_effective_snapshot;
  IF v_global.scope <> 'global' OR v_global.organization_id IS NOT NULL
    OR (v_organization.scope = 'organization' AND v_organization.organization_id <> p_organization)
    OR v_global.currency <> p_currency OR v_organization.currency <> p_currency THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_snapshot_invalid';
  END IF;
  SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount WHEN status = 'released' THEN 0 ELSE reserved_amount END), 0)
    INTO v_global_daily FROM estimate_generation_ai_budget_reservations WHERE daily_period = CURRENT_DATE AND currency = p_currency;
  SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount WHEN status = 'released' THEN 0 ELSE reserved_amount END), 0)
    INTO v_global_monthly FROM estimate_generation_ai_budget_reservations WHERE monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
  SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount WHEN status = 'released' THEN 0 ELSE reserved_amount END), 0)
    INTO v_org_daily FROM estimate_generation_ai_budget_reservations WHERE organization_id = p_organization AND daily_period = CURRENT_DATE AND currency = p_currency;
  SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount WHEN status = 'released' THEN 0 ELSE reserved_amount END), 0)
    INTO v_org_monthly FROM estimate_generation_ai_budget_reservations WHERE organization_id = p_organization AND monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
  IF v_global_daily + p_amount > v_global.daily_budget OR v_global_monthly + p_amount > v_global.monthly_budget
    OR v_org_daily + p_amount > v_organization.daily_budget OR v_org_monthly + p_amount > v_organization.monthly_budget THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_exceeded';
  END IF;
  v_id := gen_random_uuid();
  INSERT INTO estimate_generation_ai_budget_reservations
    (reservation_id, attempt_id, organization_id, session_id, global_setting_snapshot_id, organization_setting_snapshot_id,
     reserved_amount, currency, price_snapshot, immutable_fingerprint, status, daily_period, monthly_period, expires_at, created_at)
  VALUES (v_id, p_attempt, p_organization, p_session, p_global_snapshot, p_effective_snapshot, p_amount, p_currency,
    p_price, p_fingerprint, 'authorized', CURRENT_DATE, date_trunc('month', CURRENT_DATE)::date, now() + interval '5 minutes', now());
  RETURN v_id;
END;
$$;

CREATE FUNCTION eg_mark_ai_budget_sent(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'sent_pending', expires_at = now() + interval '24 hours'
    WHERE attempt_id = p_attempt AND status = 'authorized';
  IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt AND status IN ('sent_pending','reconciliation_required','settled')) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_send_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE FUNCTION eg_release_ai_budget(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'released', actual_amount = 0, settled_at = now(), expires_at = now()
    WHERE attempt_id = p_attempt AND status = 'authorized';
  IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt AND status = 'released') THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_release_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE FUNCTION eg_mark_ai_budget_reconciliation(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'reconciliation_required', expires_at = now()
    WHERE attempt_id = p_attempt AND status = 'sent_pending';
  IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt AND status IN ('reconciliation_required','settled')) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_reconciliation_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE FUNCTION eg_settle_ai_budget(p_attempt uuid, p_actual numeric, p_currency text) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'settled', actual_amount = p_actual, settled_at = now(), expires_at = now()
    WHERE attempt_id = p_attempt AND currency = p_currency AND status IN ('sent_pending','reconciliation_required') AND p_actual BETWEEN 0 AND reserved_amount;
  IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt AND status = 'settled' AND currency = p_currency AND actual_amount = p_actual) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_settlement_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE FUNCTION eg_reconcile_expired_ai_budgets(p_limit integer DEFAULT 100) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v_count integer;
BEGIN
  WITH expired AS (
    SELECT reservation_id, status FROM estimate_generation_ai_budget_reservations
    WHERE status IN ('authorized','sent_pending') AND expires_at <= now()
    ORDER BY expires_at LIMIT LEAST(GREATEST(p_limit, 1), 1000) FOR UPDATE SKIP LOCKED
  )
  UPDATE estimate_generation_ai_budget_reservations reservations
    SET status = CASE WHEN expired.status = 'authorized' THEN 'released' ELSE 'reconciliation_required' END,
        actual_amount = CASE WHEN expired.status = 'authorized' THEN 0 ELSE NULL END,
        settled_at = CASE WHEN expired.status = 'authorized' THEN now() ELSE NULL END,
        expires_at = now()
    FROM expired WHERE reservations.reservation_id = expired.reservation_id;
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END; $$;

CREATE TRIGGER eg_ai_operation_immutable BEFORE UPDATE OR DELETE ON estimate_generation_ai_operations
FOR EACH ROW EXECUTE FUNCTION eg_setting_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS eg_reconcile_expired_ai_budgets(integer); DROP FUNCTION IF EXISTS eg_settle_ai_budget(uuid,numeric,text); DROP FUNCTION IF EXISTS eg_mark_ai_budget_reconciliation(uuid); DROP FUNCTION IF EXISTS eg_release_ai_budget(uuid); DROP FUNCTION IF EXISTS eg_mark_ai_budget_sent(uuid); DROP FUNCTION IF EXISTS eg_reserve_ai_budget(uuid,uuid,bigint,bigint,bigint,bigint,numeric,text,jsonb,text); DROP FUNCTION IF EXISTS eg_pin_ai_operation_settings(uuid,bigint,bigint);');
            DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_status_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_state_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_fingerprint_ck');
            DB::statement(<<<'SQL'
UPDATE estimate_generation_ai_budget_reservations
SET status = CASE WHEN status IN ('authorized', 'sent_pending', 'reconciliation_required', 'failed') THEN 'reserved' ELSE 'settled' END,
    actual_amount = CASE
        WHEN status IN ('authorized', 'sent_pending', 'reconciliation_required', 'failed') THEN NULL
        WHEN status = 'released' THEN 0
        ELSE actual_amount
    END,
    settled_at = CASE WHEN status IN ('authorized', 'sent_pending', 'reconciliation_required', 'failed') THEN NULL ELSE settled_at END
SQL);
            DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_status_ck CHECK (status IN ('reserved','settled'))");
            DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_state_ck CHECK ((status = 'reserved' AND actual_amount IS NULL AND settled_at IS NULL) OR (status = 'settled' AND actual_amount IS NOT NULL AND settled_at IS NOT NULL))");
            DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations ALTER COLUMN status TYPE varchar(16)');
        }
        Schema::table('estimate_generation_ai_budget_reservations', function (Blueprint $table): void {
            $table->dropColumn(['immutable_fingerprint', 'expires_at']);
        });
        Schema::dropIfExists('estimate_generation_ai_operations');

        if (DB::getDriverName() === 'pgsql') {
            $this->restorePreviousBudgetFunctions();
        }
    }

    private function restorePreviousBudgetFunctions(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_reserve_ai_budget(
    p_attempt uuid, p_organization bigint, p_session bigint, p_global_snapshot bigint,
    p_organization_snapshot bigint, p_amount numeric, p_currency text, p_price jsonb
) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
    v_id uuid; v_global estimate_generation_setting_snapshots%ROWTYPE;
    v_organization estimate_generation_setting_snapshots%ROWTYPE;
    v_global_daily numeric; v_global_monthly numeric; v_org_daily numeric; v_org_monthly numeric;
BEGIN
    IF p_amount < 0 OR p_currency !~ '^[A-Z]{3}$' THEN RAISE EXCEPTION 'estimate_generation_ai_budget_request_invalid'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global', 0));
    PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-org:' || p_organization::text, 0));
    SELECT * INTO STRICT v_global FROM estimate_generation_setting_snapshots WHERE id = p_global_snapshot FOR SHARE;
    SELECT * INTO STRICT v_organization FROM estimate_generation_setting_snapshots WHERE id = p_organization_snapshot FOR SHARE;
    IF v_global.scope <> 'global' OR v_global.organization_id IS NOT NULL
       OR v_organization.scope <> 'organization' OR v_organization.organization_id <> p_organization
       OR v_global.currency <> p_currency OR v_organization.currency <> p_currency THEN
       RAISE EXCEPTION 'estimate_generation_ai_budget_snapshot_invalid';
    END IF;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_global_daily FROM estimate_generation_ai_budget_reservations WHERE daily_period = CURRENT_DATE AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_global_monthly FROM estimate_generation_ai_budget_reservations WHERE monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_org_daily FROM estimate_generation_ai_budget_reservations WHERE organization_id = p_organization AND daily_period = CURRENT_DATE AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_org_monthly FROM estimate_generation_ai_budget_reservations WHERE organization_id = p_organization AND monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
    IF v_global_daily + p_amount > v_global.daily_budget OR v_global_monthly + p_amount > v_global.monthly_budget
       OR v_org_daily + p_amount > v_organization.daily_budget OR v_org_monthly + p_amount > v_organization.monthly_budget THEN
       RAISE EXCEPTION 'estimate_generation_ai_budget_exceeded';
    END IF;
    v_id := gen_random_uuid();
    INSERT INTO estimate_generation_ai_budget_reservations
      (reservation_id, attempt_id, organization_id, session_id, global_setting_snapshot_id,
       organization_setting_snapshot_id, reserved_amount, currency, price_snapshot, status,
       daily_period, monthly_period, created_at)
    VALUES (v_id, p_attempt, p_organization, p_session, p_global_snapshot, p_organization_snapshot,
       p_amount, p_currency, p_price, 'reserved', CURRENT_DATE, date_trunc('month', CURRENT_DATE)::date, now())
    ON CONFLICT (attempt_id) DO NOTHING;
    SELECT reservation_id INTO STRICT v_id FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt;
    RETURN v_id;
END;
$$;

CREATE FUNCTION eg_settle_ai_budget(p_attempt uuid, p_actual numeric, p_currency text) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE v_updated integer;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-budget-global', 0));
    UPDATE estimate_generation_ai_budget_reservations SET status = 'settled', actual_amount = p_actual, settled_at = now()
    WHERE attempt_id = p_attempt AND currency = p_currency AND status = 'reserved' AND p_actual BETWEEN 0 AND reserved_amount;
    GET DIAGNOSTICS v_updated = ROW_COUNT;
    IF v_updated = 0 AND NOT EXISTS (
        SELECT 1 FROM estimate_generation_ai_budget_reservations
        WHERE attempt_id = p_attempt AND status = 'settled' AND currency = p_currency AND actual_amount = p_actual
    ) THEN RAISE EXCEPTION 'estimate_generation_ai_budget_settlement_invalid'; END IF;
    RETURN true;
END;
$$;
SQL);
    }
};
