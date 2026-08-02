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
        Schema::create('procurement_cycle_owner_expectation_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('purchase_request_line_id');
            $table->unsignedInteger('expectation_version');
            $table->jsonb('dimensions');
            $table->timestampTz('effective_from');
            $table->string('source_event_id', 128);
            $table->char('source_hash', 64);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unique(
                ['organization_id', 'purchase_request_line_id', 'expectation_version'],
                'proc_cycle_expectation_version_unique',
            );
            $table->unique(
                ['organization_id', 'purchase_request_line_id', 'source_event_id'],
                'proc_cycle_expectation_source_unique',
            );
        });

        Schema::create('procurement_cycle_snapshots', function (Blueprint $table): void {
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
            $table->unsignedBigInteger('sla_numerator');
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->string('quality_status', 16);
            $table->string('reconciliation_status', 16);
            $table->jsonb('totals');
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'proc_cycle_snapshot_identity_unique');
            $table->index(['organization_id', 'generated_at', 'id'], 'proc_cycle_snapshot_generated_idx');
        });

        Schema::create('procurement_cycle_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->string('row_key', 128);
            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('purchase_request_line_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('supplier_request_id')->nullable();
            $table->unsignedBigInteger('supplier_proposal_version_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('purchase_receipt_id')->nullable();
            $table->string('stage', 32);
            $table->timestampTz('stage_started_at');
            $table->timestampTz('closed_at')->nullable();
            $table->date('cohort_date');
            $table->date('outcome_cohort_date')->nullable();
            $table->boolean('cohort_mature');
            $table->string('outcome_code', 32);
            $table->jsonb('stage_timestamps');
            $table->jsonb('process_event_ids');
            $table->jsonb('stage_duration_seconds');
            $table->unsignedBigInteger('total_duration_seconds');
            $table->unsignedInteger('sla_numerator');
            $table->unsignedInteger('sla_denominator');
            $table->jsonb('quality_warnings');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'proc_cycle_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'stage', 'cohort_date', 'purchase_request_line_id', 'row_key'],
                'proc_cycle_row_default_idx',
            );
        });

        $this->installConstraints();
        $this->installAppendOnlyTriggers([
            'procurement_cycle_owner_expectation_versions',
            'procurement_cycle_snapshots',
            'procurement_cycle_rows',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_cycle_rows');
        Schema::dropIfExists('procurement_cycle_snapshots');
        Schema::dropIfExists('procurement_cycle_owner_expectation_versions');
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE procurement_cycle_snapshots ADD CONSTRAINT proc_cycle_quality_check CHECK (quality_status IN ('complete','partial','invalid'))");
        DB::statement("ALTER TABLE procurement_cycle_snapshots ADD CONSTRAINT proc_cycle_reconciliation_check CHECK (reconciliation_status IN ('matched','mismatch','not_applicable'))");
        DB::statement('ALTER TABLE procurement_cycle_snapshots ADD CONSTRAINT proc_cycle_sla_count_check CHECK (sla_numerator <= eligible_count)');
        DB::statement('ALTER TABLE procurement_cycle_rows ADD CONSTRAINT proc_cycle_row_sla_check CHECK (sla_numerator <= sla_denominator)');
    }

    private function installAppendOnlyTriggers(array $tables): void
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

        foreach ($tables as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION most_prevent_reporting_mutation_v1()");
        }
    }
};
