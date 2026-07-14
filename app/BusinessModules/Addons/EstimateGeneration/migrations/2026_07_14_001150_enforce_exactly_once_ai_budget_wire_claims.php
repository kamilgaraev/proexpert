<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('estimate_generation_ai_budget_wire_claims_require_postgresql');
        }

        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_status_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_state_ck');
        DB::statement(<<<'SQL'
UPDATE estimate_generation_ai_budget_reservations
SET status = CASE
        WHEN status = 'authorized' THEN 'reserved'
        WHEN status IN ('pending_reconciliation', 'failed') THEN 'reconciliation_required'
        ELSE status
    END,
    actual_amount = CASE WHEN status IN ('pending_reconciliation', 'failed') THEN NULL ELSE actual_amount END,
    settled_at = CASE WHEN status IN ('pending_reconciliation', 'failed') THEN NULL ELSE settled_at END,
    expires_at = CASE WHEN status IN ('pending_reconciliation', 'failed') THEN now() ELSE expires_at END
WHERE status IN ('authorized', 'pending_reconciliation', 'failed')
SQL);
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_status_ck CHECK (status IN ('reserved','sent_pending','reconciliation_required','settled','released'))");
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_state_ck CHECK ((status IN ('reserved','sent_pending','reconciliation_required') AND actual_amount IS NULL AND settled_at IS NULL) OR (status = 'settled' AND actual_amount IS NOT NULL AND settled_at IS NOT NULL) OR (status = 'released' AND actual_amount = 0 AND settled_at IS NOT NULL))");

        DB::statement('DROP FUNCTION IF EXISTS eg_mark_ai_budget_sent(uuid)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_reserve_ai_budget(
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
    p_price, p_fingerprint, 'reserved', CURRENT_DATE, date_trunc('month', CURRENT_DATE)::date, now() + interval '5 minutes', now());
  RETURN v_id;
END;
$$;

CREATE FUNCTION eg_claim_ai_budget_wire(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE v_claimed boolean;
BEGIN
  UPDATE estimate_generation_ai_budget_reservations
    SET status = 'sent_pending', expires_at = now() + interval '24 hours'
    WHERE attempt_id = p_attempt AND status = 'reserved'
    RETURNING true INTO v_claimed;
  IF v_claimed IS TRUE THEN
    RETURN true;
  END IF;
  IF EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt) THEN
    RETURN false;
  END IF;
  RAISE EXCEPTION 'estimate_generation_ai_budget_wire_claim_missing';
END; $$;

CREATE OR REPLACE FUNCTION eg_release_ai_budget(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations
    SET status = 'released', actual_amount = 0, settled_at = now(), expires_at = now()
    WHERE attempt_id = p_attempt AND status = 'reserved';
  IF NOT FOUND AND NOT EXISTS (SELECT 1 FROM estimate_generation_ai_budget_reservations WHERE attempt_id = p_attempt AND status = 'released') THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_release_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_mark_ai_budget_reconciliation(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations
    SET status = 'reconciliation_required', expires_at = now()
    WHERE attempt_id = p_attempt AND status = 'sent_pending';
  IF NOT FOUND AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_ai_budget_reservations
    WHERE attempt_id = p_attempt AND status IN ('reconciliation_required','settled')
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_reconciliation_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_settle_ai_budget(p_attempt uuid, p_actual numeric, p_currency text) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations
    SET status = 'settled', actual_amount = p_actual, settled_at = now(), expires_at = now()
    WHERE attempt_id = p_attempt AND currency = p_currency
      AND status IN ('sent_pending','reconciliation_required') AND p_actual BETWEEN 0 AND reserved_amount;
  IF NOT FOUND AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_ai_budget_reservations
    WHERE attempt_id = p_attempt AND status = 'settled' AND currency = p_currency AND actual_amount = p_actual
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_settlement_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_reconcile_expired_ai_budgets(p_limit integer DEFAULT 100) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v_count integer;
BEGIN
  WITH expired AS (
    SELECT reservation_id, status FROM estimate_generation_ai_budget_reservations
    WHERE status IN ('reserved','sent_pending') AND expires_at <= now()
    ORDER BY expires_at LIMIT LEAST(GREATEST(p_limit, 1), 1000) FOR UPDATE SKIP LOCKED
  )
  UPDATE estimate_generation_ai_budget_reservations reservations
    SET status = CASE WHEN expired.status = 'reserved' THEN 'released' ELSE 'reconciliation_required' END,
        actual_amount = CASE WHEN expired.status = 'reserved' THEN 0 ELSE NULL END,
        settled_at = CASE WHEN expired.status = 'reserved' THEN now() ELSE NULL END,
        expires_at = now()
    FROM expired WHERE reservations.reservation_id = expired.reservation_id;
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END; $$;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS eg_claim_ai_budget_wire(uuid)');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_status_ck');
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations DROP CONSTRAINT IF EXISTS eg_budget_reservation_state_ck');
        DB::statement("UPDATE estimate_generation_ai_budget_reservations SET status = CASE WHEN status = 'reserved' THEN 'authorized' ELSE 'pending_reconciliation' END WHERE status IN ('reserved','reconciliation_required')");
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_status_ck CHECK (status IN ('authorized','sent_pending','pending_reconciliation','settled','released','failed'))");
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_state_ck CHECK ((status IN ('authorized','sent_pending','pending_reconciliation') AND actual_amount IS NULL AND settled_at IS NULL) OR (status IN ('settled','released','failed') AND actual_amount IS NOT NULL AND settled_at IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_reserve_ai_budget(
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
  IF NOT FOUND AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_ai_budget_reservations
    WHERE attempt_id = p_attempt AND status IN ('sent_pending','pending_reconciliation','settled','failed')
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_send_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_mark_ai_budget_reconciliation(p_attempt uuid) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'pending_reconciliation', expires_at = now() + interval '24 hours'
    WHERE attempt_id = p_attempt AND status IN ('authorized','sent_pending');
  IF NOT FOUND AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_ai_budget_reservations
    WHERE attempt_id = p_attempt AND status IN ('pending_reconciliation','settled','failed')
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_reconciliation_state_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_settle_ai_budget(p_attempt uuid, p_actual numeric, p_currency text) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  UPDATE estimate_generation_ai_budget_reservations SET status = 'settled', actual_amount = p_actual, settled_at = now(), expires_at = now()
    WHERE attempt_id = p_attempt AND currency = p_currency
      AND status IN ('authorized','sent_pending','pending_reconciliation') AND p_actual BETWEEN 0 AND reserved_amount;
  IF NOT FOUND AND NOT EXISTS (
    SELECT 1 FROM estimate_generation_ai_budget_reservations
    WHERE attempt_id = p_attempt AND status = 'settled' AND currency = p_currency AND actual_amount = p_actual
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_budget_settlement_invalid';
  END IF;
  RETURN true;
END; $$;

CREATE OR REPLACE FUNCTION eg_reconcile_expired_ai_budgets(p_limit integer DEFAULT 100) RETURNS integer LANGUAGE plpgsql AS $$
DECLARE v_count integer;
BEGIN
  WITH expired AS (
    SELECT reservation_id, status FROM estimate_generation_ai_budget_reservations
    WHERE status IN ('authorized','sent_pending','pending_reconciliation') AND expires_at <= now()
    ORDER BY expires_at LIMIT LEAST(GREATEST(p_limit, 1), 1000) FOR UPDATE SKIP LOCKED
  )
  UPDATE estimate_generation_ai_budget_reservations reservations
    SET status = CASE WHEN expired.status = 'authorized' THEN 'released' ELSE 'pending_reconciliation' END,
        actual_amount = CASE WHEN expired.status = 'authorized' THEN 0 ELSE NULL END,
        settled_at = CASE WHEN expired.status = 'authorized' THEN now() ELSE NULL END,
        expires_at = CASE WHEN expired.status = 'authorized' THEN now() ELSE now() + interval '24 hours' END
    FROM expired WHERE reservations.reservation_id = expired.reservation_id;
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END; $$;
SQL);
    }
};
