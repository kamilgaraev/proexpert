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
        Schema::create('estimate_generation_ai_budget_reservations', function (Blueprint $table): void {
            $table->uuid('reservation_id')->primary();
            $table->uuid('attempt_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('global_setting_snapshot_id');
            $table->unsignedBigInteger('organization_setting_snapshot_id');
            $table->decimal('reserved_amount', 20, 8);
            $table->decimal('actual_amount', 20, 8)->nullable();
            $table->char('currency', 3);
            $table->jsonb('price_snapshot');
            $table->string('status', 16);
            $table->date('daily_period');
            $table->date('monthly_period');
            $table->timestampTz('created_at');
            $table->timestampTz('settled_at')->nullable();
            $table->foreign('global_setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
            $table->foreign('organization_setting_snapshot_id')->references('id')->on('estimate_generation_setting_snapshots')->restrictOnDelete();
            $table->foreign(['session_id', 'organization_id'], 'eg_budget_reservation_session_fk')
                ->references(['id', 'organization_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->index(['daily_period', 'currency', 'status'], 'eg_budget_global_daily_idx');
            $table->index(['organization_id', 'monthly_period', 'currency', 'status'], 'eg_budget_org_month_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_status_ck CHECK (status IN ('reserved','settled'))");
        DB::statement('ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_amount_ck CHECK (reserved_amount >= 0 AND actual_amount >= 0 AND (actual_amount IS NULL OR actual_amount <= reserved_amount))');
        DB::statement("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_state_ck CHECK ((status = 'reserved' AND actual_amount IS NULL AND settled_at IS NULL) OR (status = 'settled' AND actual_amount IS NOT NULL AND settled_at IS NOT NULL))");
        DB::unprepared("ALTER TABLE estimate_generation_ai_budget_reservations ADD CONSTRAINT eg_budget_reservation_price_ck CHECK (jsonb_typeof(price_snapshot) = 'object' AND price_snapshot ?& ARRAY['currency','version','effective_at'])");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_reserve_ai_budget(
    p_attempt uuid,
    p_organization bigint,
    p_session bigint,
    p_global_snapshot bigint,
    p_organization_snapshot bigint,
    p_amount numeric,
    p_currency text,
    p_price jsonb
) RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
    v_id uuid;
    v_global estimate_generation_setting_snapshots%ROWTYPE;
    v_organization estimate_generation_setting_snapshots%ROWTYPE;
    v_global_daily numeric;
    v_global_monthly numeric;
    v_org_daily numeric;
    v_org_monthly numeric;
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
      INTO v_global_daily FROM estimate_generation_ai_budget_reservations
      WHERE daily_period = CURRENT_DATE AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_global_monthly FROM estimate_generation_ai_budget_reservations
      WHERE monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_org_daily FROM estimate_generation_ai_budget_reservations
      WHERE organization_id = p_organization AND daily_period = CURRENT_DATE AND currency = p_currency;
    SELECT COALESCE(sum(CASE WHEN status = 'settled' THEN actual_amount ELSE reserved_amount END), 0)
      INTO v_org_monthly FROM estimate_generation_ai_budget_reservations
      WHERE organization_id = p_organization AND monthly_period = date_trunc('month', CURRENT_DATE)::date AND currency = p_currency;
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
    UPDATE estimate_generation_ai_budget_reservations
       SET status = 'settled', actual_amount = p_actual, settled_at = now()
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

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS eg_settle_ai_budget(uuid, numeric, text)');
            DB::statement('DROP FUNCTION IF EXISTS eg_reserve_ai_budget(uuid, bigint, bigint, bigint, bigint, numeric, text, jsonb)');
        }
        Schema::dropIfExists('estimate_generation_ai_budget_reservations');
    }
};
