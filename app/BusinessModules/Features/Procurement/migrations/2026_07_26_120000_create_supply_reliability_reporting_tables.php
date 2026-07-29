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
            $table->boolean('otif');
            $table->unsignedSmallInteger('otif_numerator');
            $table->unsignedSmallInteger('eligible_denominator');
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
        Schema::dropIfExists('supply_reliability_rows');
        Schema::dropIfExists('supply_reliability_snapshots');
        Schema::dropIfExists('supply_reliability_policy_versions');
        Schema::dropIfExists('supply_lifecycle_events');
        Schema::dropIfExists('purchase_order_promise_versions');
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
        DB::statement('ALTER TABLE supply_reliability_policy_versions ADD CONSTRAINT supply_policy_tolerance_check CHECK (quantity_tolerance >= 0 AND on_time_cutoff_seconds >= 0)');
        DB::statement("ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_quality_check CHECK (quality_status IN ('complete','partial','invalid'))");
        DB::statement("ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_reconciliation_check CHECK (reconciliation_status IN ('matched','mismatch','not_applicable'))");
        DB::statement('ALTER TABLE supply_reliability_snapshots ADD CONSTRAINT supply_snapshot_otif_check CHECK (otif_numerator <= eligible_count)');
        DB::statement('ALTER TABLE supply_reliability_rows ADD CONSTRAINT supply_row_otif_check CHECK (otif_numerator <= eligible_denominator AND eligible_denominator <= 1)');
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
