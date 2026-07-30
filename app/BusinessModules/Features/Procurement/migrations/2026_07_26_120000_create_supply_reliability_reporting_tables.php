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
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->timestampTz('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->string('reversal_reason_code', 64)->nullable();
            $table->unsignedBigInteger('reversal_warehouse_movement_id')->nullable();
            $table->string('reversal_idempotency_key', 128)->nullable();
            $table->index(['purchase_order_item_id', 'reversed_at'], 'receipt_line_reversal_idx');
            $table->unique(
                ['purchase_receipt_id', 'reversal_idempotency_key'],
                'receipt_line_reversal_idempotency_unique',
            );
            $table->foreign('reversed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversal_warehouse_movement_id')->references('id')->on('warehouse_movements')->nullOnDelete();
        });

        Schema::create('purchase_receipt_inventory_lots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_receipt_line_id');
            $table->unsignedBigInteger('warehouse_balance_id');
            $table->unsignedBigInteger('receipt_warehouse_movement_id');
            $table->decimal('original_quantity', 24, 6);
            $table->decimal('reversed_quantity', 24, 6)->default(0);
            $table->string('unit_dimension', 128);
            $table->string('unit_code', 64);
            $table->string('conversion_version', 128);
            $table->timestampsTz();
            $table->unique('purchase_receipt_line_id', 'receipt_inventory_lot_line_unique');
            $table->unique('receipt_warehouse_movement_id', 'receipt_inventory_lot_movement_unique');
            $table->foreign('purchase_receipt_line_id')->references('id')->on('purchase_receipt_lines')->restrictOnDelete();
            $table->foreign('warehouse_balance_id')->references('id')->on('warehouse_balances')->restrictOnDelete();
            $table->foreign('receipt_warehouse_movement_id')->references('id')->on('warehouse_movements')->restrictOnDelete();
        });

        Schema::create('purchase_order_promise_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_order_item_id');
            $table->unsignedInteger('promise_version');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->decimal('ordered_quantity', 24, 6);
            $table->unsignedBigInteger('ordered_value_minor')->nullable();
            $table->string('value_basis', 128)->nullable();
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->timestampTz('promised_at');
            $table->string('promise_timezone', 64);
            $table->char('currency', 3)->nullable();
            $table->string('currency_source', 64)->nullable();
            $table->string('tax_basis', 32);
            $table->string('freight_basis', 32);
            $table->unsignedInteger('source_version');
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->char('source_hash', 64);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unique(
                ['organization_id', 'purchase_order_item_id', 'promise_version'],
                'supply_promise_version_unique',
            );
            $table->unique(
                ['organization_id', 'purchase_order_item_id', 'source_version'],
                'supply_promise_source_version_unique',
            );
            $table->index(
                ['organization_id', 'supplier_id', 'promised_at', 'purchase_order_item_id', 'promise_version'],
                'supply_promise_default_idx',
            );
        });

        Schema::create('supply_lifecycle_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_order_item_id');
            $table->unsignedBigInteger('promise_version_id');
            $table->string('event_type', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_version');
            $table->decimal('signed_quantity', 24, 6);
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('reversed_event_id')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('source_hash', 64);
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version', 'event_type'],
                'supply_event_source_unique',
            );
            $table->unique(['organization_id', 'idempotency_key'], 'supply_event_idempotency_unique');
            $table->index(
                ['organization_id', 'purchase_order_item_id', 'occurred_at', 'id'],
                'supply_event_timeline_idx',
            );
        });

        Schema::create('supply_reliability_policy_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedInteger('policy_version');
            $table->integer('on_time_cutoff_seconds');
            $table->decimal('quantity_tolerance', 24, 6);
            $table->boolean('exclude_cancellation_before_send');
            $table->jsonb('post_send_exclusion_reason_codes');
            $table->unsignedInteger('maturity_seconds')->default(0);
            $table->unsignedInteger('freshness_ttl_seconds')->default(86400);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->char('source_hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'policy_version'], 'supply_policy_version_unique');
        });

        Schema::create('supply_reliability_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('scope_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 32);
            $table->string('source_schema_version', 32);
            $table->unsignedBigInteger('policy_version_id');
            $table->timestampTz('as_of');
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('eligible_count');
            $table->unsignedBigInteger('otif_numerator');
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->string('quality_status', 16);
            $table->string('reconciliation_status', 16);
            $table->jsonb('totals');
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'supply_snapshot_identity_unique');
        });

        Schema::create('supply_reliability_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->string('row_key', 128);
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_order_item_id');
            $table->unsignedBigInteger('promise_version_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->timestampTz('original_promised_at');
            $table->string('promised_month', 7);
            $table->string('delay_bucket', 32);
            $table->decimal('ordered_quantity', 24, 6);
            $table->decimal('net_received_quantity', 24, 6);
            $table->string('unit_dimension', 32);
            $table->string('unit_code', 32);
            $table->string('conversion_version', 64);
            $table->timestampTz('first_qualifying_receipt_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->boolean('eligible');
            $table->boolean('on_time');
            $table->boolean('in_full');
            $table->boolean('stable_in_full');
            $table->boolean('mature');
            $table->boolean('otif');
            $table->unsignedSmallInteger('otif_numerator');
            $table->unsignedSmallInteger('eligible_denominator');
            $table->decimal('quantity_otif_numerator', 24, 6);
            $table->decimal('quantity_otif_denominator', 24, 6);
            $table->unsignedBigInteger('value_otif_numerator_minor')->nullable();
            $table->unsignedBigInteger('value_otif_denominator_minor')->nullable();
            $table->char('value_currency', 3)->nullable();
            $table->string('value_basis', 128)->nullable();
            $table->jsonb('quality_warnings');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'supply_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'supplier_id', 'project_id', 'promised_month', 'delay_bucket', 'row_key'],
                'supply_row_filter_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'original_promised_at', 'supplier_id', 'project_id', 'purchase_order_id', 'purchase_order_item_id', 'row_key'],
                'supply_row_default_idx',
            );
        });

        $this->installConstraints();
        $this->installAppendOnlyTriggers([
            'purchase_order_promise_versions',
            'supply_lifecycle_events',
            'supply_reliability_policy_versions',
            'supply_reliability_snapshots',
            'supply_reliability_rows',
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS purchase_receipt_inventory_lot_source_identity ON purchase_receipt_inventory_lots');
            DB::statement('DROP FUNCTION IF EXISTS most_receipt_inventory_lot_source_identity_v1()');
            DB::statement('DROP TRIGGER IF EXISTS purchase_receipt_inventory_lot_identity ON purchase_receipt_inventory_lots');
            DB::statement('DROP FUNCTION IF EXISTS most_receipt_inventory_lot_identity_v1()');
            DB::statement('DROP TRIGGER IF EXISTS purchase_receipt_line_reversal_identity ON purchase_receipt_lines');
            DB::statement('DROP FUNCTION IF EXISTS most_purchase_receipt_line_reversal_v1()');
        }
        Schema::dropIfExists('supply_reliability_rows');
        Schema::dropIfExists('supply_reliability_snapshots');
        Schema::dropIfExists('supply_reliability_policy_versions');
        Schema::dropIfExists('supply_lifecycle_events');
        Schema::dropIfExists('purchase_order_promise_versions');
        Schema::dropIfExists('purchase_receipt_inventory_lots');
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['reversed_by_user_id']);
            $table->dropForeign(['reversal_warehouse_movement_id']);
            $table->dropIndex('receipt_line_reversal_idx');
            $table->dropUnique('receipt_line_reversal_idempotency_unique');
            $table->dropColumn([
                'reversed_at',
                'reversed_by_user_id',
                'reversal_reason_code',
                'reversal_warehouse_movement_id',
                'reversal_idempotency_key',
            ]);
        });
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE purchase_order_promise_versions ADD CONSTRAINT supply_promise_version_check CHECK (promise_version > 0 AND source_version > 0 AND ordered_quantity > 0)');
        DB::statement('ALTER TABLE purchase_order_promise_versions ADD CONSTRAINT supply_promise_interval_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement("ALTER TABLE supply_lifecycle_events ADD CONSTRAINT supply_event_type_check CHECK (event_type IN ('sent','confirmed','received','receipt_reversed','returned','cancelled'))");
        DB::statement("ALTER TABLE supply_lifecycle_events ADD CONSTRAINT supply_event_quantity_sign_check CHECK ((event_type = 'received' AND signed_quantity > 0) OR (event_type IN ('receipt_reversed','returned') AND signed_quantity < 0) OR (event_type IN ('sent','confirmed','cancelled') AND signed_quantity = 0))");
        DB::statement("ALTER TABLE supply_lifecycle_events ADD CONSTRAINT supply_event_reversal_check CHECK ((event_type = 'receipt_reversed' AND reversed_event_id IS NOT NULL) OR (event_type <> 'receipt_reversed' AND reversed_event_id IS NULL))");
        DB::statement('ALTER TABLE supply_reliability_policy_versions ADD CONSTRAINT supply_policy_tolerance_check CHECK (quantity_tolerance >= 0 AND on_time_cutoff_seconds >= 0 AND maturity_seconds >= 0)');
        DB::statement("ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_quality_check CHECK (quality_status IN ('complete','partial','invalid'))");
        DB::statement("ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_reconciliation_check CHECK (reconciliation_status IN ('matched','mismatch','not_applicable'))");
        DB::statement('ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_otif_check CHECK (otif_numerator <= eligible_count)');
        DB::statement('ALTER TABLE supply_reliability_rows ADD CONSTRAINT supply_row_otif_check CHECK (otif_numerator <= eligible_denominator AND eligible_denominator <= 1)');
        DB::statement('ALTER TABLE supply_reliability_rows ADD CONSTRAINT supply_row_quantity_otif_check CHECK (quantity_otif_numerator >= 0 AND quantity_otif_numerator <= quantity_otif_denominator)');
        DB::statement('ALTER TABLE supply_reliability_rows ADD CONSTRAINT supply_row_value_otif_check CHECK (value_otif_numerator_minor IS NULL OR (value_otif_denominator_minor IS NOT NULL AND value_otif_numerator_minor <= value_otif_denominator_minor))');
        DB::statement('ALTER TABLE supply_reliability_rows ADD CONSTRAINT supply_row_value_basis_check CHECK ((value_otif_numerator_minor IS NULL AND value_currency IS NULL AND value_basis IS NULL) OR (value_otif_numerator_minor IS NOT NULL AND value_currency IS NOT NULL AND value_basis IS NOT NULL))');
        DB::statement('ALTER TABLE purchase_receipt_inventory_lots ADD CONSTRAINT receipt_inventory_lot_quantity_check CHECK (original_quantity > 0 AND reversed_quantity >= 0 AND reversed_quantity <= original_quantity)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_receipt_inventory_lot_source_identity_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE source_line purchase_receipt_lines%ROWTYPE;
DECLARE source_receipt purchase_receipts%ROWTYPE;
DECLARE source_item purchase_order_items%ROWTYPE;
DECLARE source_movement warehouse_movements%ROWTYPE;
DECLARE source_balance warehouse_balances%ROWTYPE;
BEGIN
    SELECT * INTO source_line
      FROM purchase_receipt_lines
     WHERE id = NEW.purchase_receipt_line_id;
    SELECT * INTO source_receipt
      FROM purchase_receipts
     WHERE id = source_line.purchase_receipt_id;
    SELECT * INTO source_item
      FROM purchase_order_items
     WHERE id = source_line.purchase_order_item_id;
    SELECT * INTO source_movement
      FROM warehouse_movements
     WHERE id = NEW.receipt_warehouse_movement_id;
    SELECT * INTO source_balance
      FROM warehouse_balances
     WHERE id = NEW.warehouse_balance_id;

    IF source_line.id IS NULL
       OR source_receipt.id IS NULL
       OR source_item.id IS NULL
       OR source_item.material_id IS NULL
       OR source_movement.id IS NULL
       OR source_balance.id IS NULL
       OR NEW.organization_id <> source_receipt.organization_id
       OR NEW.original_quantity <> source_line.quantity_received
       OR NEW.original_quantity <> source_movement.quantity
       OR source_movement.organization_id <> source_receipt.organization_id
       OR source_movement.warehouse_id <> source_receipt.warehouse_id
       OR source_movement.material_id <> source_item.material_id
       OR source_movement.movement_type <> 'receipt'
       OR (source_movement.metadata->>'purchase_order_item_id') IS DISTINCT FROM source_item.id::text
       OR (source_movement.metadata->>'batch_number') IS DISTINCT FROM
          ('purchase-receipt-line:' || source_line.id::text)
       OR source_balance.organization_id <> source_receipt.organization_id
       OR source_balance.warehouse_id <> source_receipt.warehouse_id
       OR source_balance.material_id <> source_item.material_id
       OR source_balance.batch_number IS DISTINCT FROM
          ('purchase-receipt-line:' || source_line.id::text) THEN
        RAISE EXCEPTION 'receipt inventory lot does not match its source receipt'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END
$$;
CREATE TRIGGER purchase_receipt_inventory_lot_source_identity
BEFORE INSERT ON purchase_receipt_inventory_lots
FOR EACH ROW EXECUTE FUNCTION most_receipt_inventory_lot_source_identity_v1();

CREATE OR REPLACE FUNCTION most_purchase_receipt_line_reversal_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.purchase_receipt_id <> OLD.purchase_receipt_id
       OR NEW.purchase_order_item_id <> OLD.purchase_order_item_id
       OR NEW.quantity_received <> OLD.quantity_received
       OR NEW.price <> OLD.price
       OR NEW.total_amount <> OLD.total_amount THEN
        RAISE EXCEPTION 'purchase receipt line source identity is immutable' USING ERRCODE = '55000';
    END IF;
    IF OLD.reversed_at IS NULL AND NEW.reversed_at IS NULL THEN
        IF NEW.reversed_by_user_id IS NOT NULL
           OR NEW.reversal_reason_code IS NOT NULL
           OR NEW.reversal_warehouse_movement_id IS NOT NULL
           OR NEW.reversal_idempotency_key IS NOT NULL THEN
            RAISE EXCEPTION 'purchase receipt reversal must be atomic' USING ERRCODE = '55000';
        END IF;
    ELSIF OLD.reversed_at IS NULL THEN
        IF NEW.reversed_by_user_id IS NULL
           OR NEW.reversal_reason_code IS NULL
           OR NEW.reversal_warehouse_movement_id IS NULL
           OR NEW.reversal_idempotency_key IS NULL THEN
            RAISE EXCEPTION 'purchase receipt reversal must be complete' USING ERRCODE = '55000';
        END IF;
    ELSIF NEW.reversed_at IS DISTINCT FROM OLD.reversed_at
       OR NEW.reversed_by_user_id IS DISTINCT FROM OLD.reversed_by_user_id
       OR NEW.reversal_reason_code IS DISTINCT FROM OLD.reversal_reason_code
       OR NEW.reversal_warehouse_movement_id IS DISTINCT FROM OLD.reversal_warehouse_movement_id
       OR NEW.reversal_idempotency_key IS DISTINCT FROM OLD.reversal_idempotency_key THEN
        RAISE EXCEPTION 'purchase receipt reversal is immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER purchase_receipt_line_reversal_identity
BEFORE UPDATE ON purchase_receipt_lines
FOR EACH ROW EXECUTE FUNCTION most_purchase_receipt_line_reversal_v1();

CREATE OR REPLACE FUNCTION most_receipt_inventory_lot_identity_v1() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.organization_id <> OLD.organization_id
       OR NEW.purchase_receipt_line_id <> OLD.purchase_receipt_line_id
       OR NEW.warehouse_balance_id <> OLD.warehouse_balance_id
       OR NEW.receipt_warehouse_movement_id <> OLD.receipt_warehouse_movement_id
       OR NEW.original_quantity <> OLD.original_quantity
       OR NEW.unit_dimension <> OLD.unit_dimension
       OR NEW.unit_code <> OLD.unit_code
       OR NEW.conversion_version <> OLD.conversion_version THEN
        RAISE EXCEPTION 'receipt inventory lot identity is immutable' USING ERRCODE = '55000';
    END IF;
    IF OLD.reversed_quantity = 0
       AND NEW.reversed_quantity NOT IN (0, OLD.original_quantity) THEN
        RAISE EXCEPTION 'receipt inventory reversal must be exact' USING ERRCODE = '55000';
    END IF;
    IF OLD.reversed_quantity = OLD.original_quantity
       AND NEW.reversed_quantity <> OLD.reversed_quantity THEN
        RAISE EXCEPTION 'receipt inventory reversal is immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END
$$;
CREATE TRIGGER purchase_receipt_inventory_lot_identity
BEFORE UPDATE ON purchase_receipt_inventory_lots
FOR EACH ROW EXECUTE FUNCTION most_receipt_inventory_lot_identity_v1()
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
