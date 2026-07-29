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
        Schema::create('warehouse_inventory_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id');
            $table->unsignedBigInteger('source_movement_id');
            $table->unsignedInteger('source_version');
            $table->string('event_type', 32);
            $table->decimal('on_hand_delta', 24, 6);
            $table->decimal('reserved_delta', 24, 6);
            $table->string('transfer_pair_key', 128)->nullable();
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('currency_source', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->string('opening_basis', 32)->nullable();
            $table->char('source_hash', 64);
            $table->jsonb('source_refs');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unique(
                ['organization_id', 'source_movement_id', 'source_version', 'event_type'],
                'inventory_event_source_unique',
            );
            $table->index(
                ['organization_id', 'warehouse_id', 'material_id', 'occurred_at', 'id'],
                'inventory_event_timeline_idx',
            );
            $table->index(
                ['organization_id', 'transfer_pair_key'],
                'inventory_event_transfer_pair_idx',
            );
        });

        Schema::create('warehouse_daily_balance_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('gap_count');
            $table->string('reconciliation_status', 16);
            $table->timestampTz('generated_at');
            $table->unique(
                ['organization_id', 'from_date', 'to_date', 'source_hash'],
                'daily_balance_snapshot_identity_unique',
            );
        });

        Schema::create('warehouse_daily_balance_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('balance_snapshot_id', 26);
            $table->string('row_key', 128);
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id');
            $table->date('balance_date');
            $table->decimal('opening_on_hand', 24, 6);
            $table->decimal('receipts', 24, 6);
            $table->decimal('issues', 24, 6);
            $table->decimal('inbound_transfers', 24, 6);
            $table->decimal('outbound_transfers', 24, 6);
            $table->decimal('returns', 24, 6);
            $table->decimal('positive_adjustments', 24, 6);
            $table->decimal('negative_adjustments', 24, 6);
            $table->decimal('closing_on_hand', 24, 6);
            $table->decimal('reserved_quantity', 24, 6);
            $table->decimal('available_quantity', 24, 6);
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('currency_source', 64)->nullable();
            $table->jsonb('quality_warnings');
            $table->unique(
                ['organization_id', 'balance_snapshot_id', 'row_key'],
                'daily_balance_row_key_unique',
            );
            $table->unique(
                ['organization_id', 'balance_snapshot_id', 'warehouse_id', 'project_id', 'material_id', 'balance_date', 'unit_code'],
                'daily_balance_grain_unique',
            );
            $table->index(
                ['organization_id', 'balance_snapshot_id', 'warehouse_id', 'material_id', 'balance_date', 'row_key'],
                'daily_balance_default_idx',
            );
        });

        Schema::create('inventory_demand_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id');
            $table->unsignedInteger('horizon_days');
            $table->decimal('approved_quantity', 24, 6);
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_version');
            $table->timestampTz('approved_at');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->char('source_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version'],
                'inventory_demand_source_unique',
            );
        });

        Schema::create('inventory_reorder_policy_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedInteger('policy_version');
            $table->decimal('minimum_quantity', 24, 6);
            $table->decimal('reorder_point_quantity', 24, 6);
            $table->decimal('target_quantity', 24, 6);
            $table->unsignedInteger('lead_time_days');
            $table->decimal('safety_stock_quantity', 24, 6);
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->unsignedInteger('freshness_ttl_seconds')->default(86400);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->char('source_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(
                ['organization_id', 'warehouse_id', 'project_id', 'material_id', 'policy_version'],
                'inventory_reorder_policy_version_unique',
            );
        });

        Schema::create('inventory_risk_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('scope_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 32);
            $table->string('source_schema_version', 32);
            $table->char('balance_snapshot_id', 26);
            $table->timestampTz('as_of');
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('gap_count');
            $table->string('quality_status', 16);
            $table->string('reconciliation_status', 16);
            $table->jsonb('totals');
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'inventory_risk_snapshot_identity_unique');
        });

        Schema::create('inventory_risk_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->string('row_key', 128);
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id');
            $table->date('balance_date');
            $table->string('risk_status', 32);
            $table->decimal('opening_on_hand', 24, 6);
            $table->decimal('closing_on_hand', 24, 6);
            $table->decimal('reserved_quantity', 24, 6);
            $table->decimal('available_quantity', 24, 6);
            $table->decimal('consumption_quantity', 24, 6);
            $table->decimal('turnover', 20, 8)->nullable();
            $table->decimal('cost_turnover', 20, 8)->nullable();
            $table->decimal('days_on_hand', 20, 8)->nullable();
            $table->timestampTz('stockout_at')->nullable();
            $table->bigInteger('consumption_value_minor')->nullable();
            $table->bigInteger('on_hand_value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('recommended_order_quantity', 24, 6)->nullable();
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->unsignedBigInteger('demand_snapshot_id')->nullable();
            $table->unsignedBigInteger('reorder_policy_version_id')->nullable();
            $table->jsonb('quality_warnings');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'inventory_risk_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'balance_date', 'warehouse_id', 'project_id', 'material_id', 'risk_status', 'row_key'],
                'inventory_risk_row_default_idx',
            );
        });

        $this->installConstraints();
        $this->installAppendOnlyTriggers([
            'warehouse_inventory_events',
            'warehouse_daily_balance_snapshots',
            'warehouse_daily_balance_rows',
            'inventory_demand_snapshots',
            'inventory_reorder_policy_versions',
            'inventory_risk_snapshots',
            'inventory_risk_rows',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_risk_rows');
        Schema::dropIfExists('inventory_risk_snapshots');
        Schema::dropIfExists('inventory_reorder_policy_versions');
        Schema::dropIfExists('inventory_demand_snapshots');
        Schema::dropIfExists('warehouse_daily_balance_rows');
        Schema::dropIfExists('warehouse_daily_balance_snapshots');
        Schema::dropIfExists('warehouse_inventory_events');
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_prevent_reporting_mutation_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'reporting records are append-only' USING ERRCODE = '55000';
END
$$
SQL);
        DB::statement("ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_type_check CHECK (event_type IN ('receipt','issue','transfer_in','transfer_out','return','adjustment','reservation','unreservation','reserved_issue'))");
        DB::statement('ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_source_version_check CHECK (source_version > 0)');
        DB::statement("ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_delta_check CHECK ((event_type IN ('receipt','transfer_in','return') AND on_hand_delta > 0 AND reserved_delta = 0) OR (event_type IN ('issue','transfer_out') AND on_hand_delta < 0 AND reserved_delta = 0) OR (event_type = 'reservation' AND on_hand_delta = 0 AND reserved_delta > 0) OR (event_type = 'unreservation' AND on_hand_delta = 0 AND reserved_delta < 0) OR (event_type = 'reserved_issue' AND on_hand_delta < 0 AND reserved_delta = on_hand_delta) OR event_type = 'adjustment')");
        DB::statement("ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_pair_key_check CHECK ((event_type IN ('transfer_in','transfer_out') AND transfer_pair_key IS NOT NULL) OR (event_type NOT IN ('transfer_in','transfer_out') AND transfer_pair_key IS NULL))");
        DB::statement('ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_valuation_check CHECK ((unit_price_minor IS NULL AND currency IS NULL AND currency_source IS NULL) OR (unit_price_minor IS NOT NULL AND currency IS NOT NULL AND currency_source IS NOT NULL))');
        DB::statement('ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_price_check CHECK (unit_price_minor IS NULL OR unit_price_minor >= 0)');
        DB::statement("ALTER TABLE warehouse_inventory_events ADD CONSTRAINT inventory_event_opening_basis_check CHECK (opening_basis IS NULL OR opening_basis IN ('verified_zero','opening_inventory','prior_verified_closing'))");
        DB::statement('ALTER TABLE warehouse_daily_balance_rows ADD CONSTRAINT daily_balance_nonnegative_check CHECK (opening_on_hand >= 0 AND closing_on_hand >= 0 AND reserved_quantity >= 0 AND available_quantity >= 0)');
        DB::statement('ALTER TABLE warehouse_daily_balance_rows ADD CONSTRAINT daily_balance_available_check CHECK (available_quantity = closing_on_hand - reserved_quantity)');
        DB::statement('ALTER TABLE warehouse_daily_balance_rows ADD CONSTRAINT daily_balance_equation_check CHECK (closing_on_hand = opening_on_hand + receipts + inbound_transfers + returns + positive_adjustments - issues - outbound_transfers - negative_adjustments)');
        DB::statement('ALTER TABLE inventory_demand_snapshots ADD CONSTRAINT inventory_demand_values_check CHECK (horizon_days > 0 AND approved_quantity >= 0 AND source_version > 0)');
        DB::statement('ALTER TABLE inventory_reorder_policy_versions ADD CONSTRAINT inventory_policy_order_check CHECK (policy_version > 0 AND minimum_quantity >= 0 AND reorder_point_quantity >= minimum_quantity AND target_quantity >= reorder_point_quantity AND safety_stock_quantity >= 0)');
        DB::statement("ALTER TABLE inventory_risk_snapshots ADD CONSTRAINT inventory_risk_quality_check CHECK (quality_status IN ('complete','partial','invalid'))");
        DB::statement("ALTER TABLE inventory_risk_snapshots ADD CONSTRAINT inventory_risk_reconciliation_check CHECK (reconciliation_status IN ('matched','mismatch','not_applicable'))");
        DB::statement('ALTER TABLE inventory_risk_rows ADD CONSTRAINT inventory_risk_available_check CHECK (available_quantity = closing_on_hand - reserved_quantity AND available_quantity >= 0)');
        DB::statement('ALTER TABLE inventory_risk_rows ADD CONSTRAINT inventory_risk_values_check CHECK ((turnover IS NULL OR turnover >= 0) AND (cost_turnover IS NULL OR cost_turnover >= 0) AND (days_on_hand IS NULL OR days_on_hand >= 0) AND (consumption_value_minor IS NULL OR consumption_value_minor >= 0) AND (on_hand_value_minor IS NULL OR on_hand_value_minor >= 0) AND (recommended_order_quantity IS NULL OR recommended_order_quantity >= 0))');
        $this->installTransferPairConstraint();
        $this->installDailyRecurrenceConstraint();
    }

    private function installTransferPairConstraint(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_inventory_transfer_pair_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE pair_count integer;
DECLARE on_hand_sum numeric;
DECLARE dimension_count integer;
BEGIN
    IF NEW.transfer_pair_key IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT COUNT(*), COALESCE(SUM(on_hand_delta), 0), COUNT(DISTINCT unit_dimension || ':' || unit_code || ':' || conversion_version)
      INTO pair_count, on_hand_sum, dimension_count
      FROM warehouse_inventory_events
     WHERE organization_id = NEW.organization_id
       AND transfer_pair_key = NEW.transfer_pair_key;
    IF dimension_count <> 1 OR on_hand_sum > 0 THEN
        RAISE EXCEPTION 'warehouse transfer pair is incomplete' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;
CREATE CONSTRAINT TRIGGER warehouse_inventory_transfer_pair
AFTER INSERT ON warehouse_inventory_events
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (NEW.transfer_pair_key IS NOT NULL)
EXECUTE FUNCTION most_inventory_transfer_pair_v1()
SQL);
    }

    private function installDailyRecurrenceConstraint(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_inventory_daily_recurrence_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE previous_closing numeric;
BEGIN
    SELECT closing_on_hand INTO previous_closing
      FROM warehouse_daily_balance_rows
     WHERE organization_id = NEW.organization_id
       AND balance_snapshot_id = NEW.balance_snapshot_id
       AND warehouse_id = NEW.warehouse_id
       AND project_id IS NOT DISTINCT FROM NEW.project_id
       AND material_id = NEW.material_id
       AND unit_code = NEW.unit_code
       AND balance_date < NEW.balance_date
     ORDER BY balance_date DESC
     LIMIT 1;
    IF previous_closing IS NOT NULL AND previous_closing <> NEW.opening_on_hand THEN
        RAISE EXCEPTION 'warehouse daily balance recurrence mismatch' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$$;
CREATE CONSTRAINT TRIGGER warehouse_daily_balance_recurrence
AFTER INSERT ON warehouse_daily_balance_rows
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION most_inventory_daily_recurrence_v1()
SQL);
    }

    private function installAppendOnlyTriggers(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION most_prevent_reporting_mutation_v1()");
        }
    }
};
