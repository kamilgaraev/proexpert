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
        Schema::create('supplier_award_decision_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('decision_id');
            $table->unsignedInteger('decision_version');
            $table->unsignedBigInteger('purchase_request_id')->nullable();
            $table->unsignedBigInteger('supplier_request_id');
            $table->unsignedBigInteger('selected_proposal_version_id');
            $table->unsignedBigInteger('cheapest_proposal_version_id');
            $table->unsignedBigInteger('median_proposal_version_id');
            $table->jsonb('invited_supplier_ids');
            $table->jsonb('comparable_proposal_version_ids');
            $table->jsonb('excluded_comparisons');
            $table->char('comparable_set_hash', 64);
            $table->boolean('is_lowest_price_selected');
            $table->string('decision_reason', 1000)->nullable();
            $table->unsignedBigInteger('selected_by')->nullable();
            $table->timestampTz('selected_at');
            $table->char('source_hash', 64);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->unique(
                ['organization_id', 'decision_id', 'decision_version'],
                'supplier_award_decision_version_unique',
            );
            $table->unique(
                ['organization_id', 'decision_id', 'source_hash'],
                'supplier_award_decision_source_unique',
            );
            $table->index(
                ['organization_id', 'supplier_request_id', 'selected_at', 'decision_id'],
                'supplier_award_decision_timeline_idx',
            );
        });

        Schema::create('supplier_award_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('scope_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 32);
            $table->string('source_schema_version', 32);
            $table->timestampTz('as_of');
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('eligible_count');
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->string('quality_status', 16);
            $table->string('reconciliation_status', 16);
            $table->jsonb('totals');
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'supplier_award_snapshot_identity_unique');
        });

        Schema::create('supplier_award_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->string('row_key', 128);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('decision_id');
            $table->unsignedInteger('decision_version');
            $table->unsignedBigInteger('proposal_version_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('selected_proposal_version_id');
            $table->unsignedBigInteger('cheapest_proposal_version_id');
            $table->unsignedBigInteger('median_proposal_version_id');
            $table->unsignedInteger('invited_count');
            $table->unsignedInteger('responded_count');
            $table->char('currency', 3);
            $table->bigInteger('selected_amount_minor');
            $table->bigInteger('cheapest_amount_minor');
            $table->bigInteger('median_amount_minor');
            $table->bigInteger('premium_minor');
            $table->decimal('premium_ratio', 20, 8);
            $table->bigInteger('median_variance_minor');
            $table->decimal('median_variance_ratio', 20, 8);
            $table->decimal('participation_ratio', 20, 8);
            $table->char('comparable_set_hash', 64);
            $table->boolean('non_lowest_selected');
            $table->string('decision_reason', 1000)->nullable();
            $table->jsonb('excluded_comparisons');
            $table->timestampTz('selected_at');
            $table->jsonb('quality_warnings');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'supplier_award_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'currency', 'selected_at', 'decision_id', 'row_key'],
                'supplier_award_row_default_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'material_id', 'supplier_id', 'row_key'],
                'supplier_award_row_filter_idx',
            );
        });

        $this->installConstraints();
        $this->installAppendOnlyTriggers([
            'supplier_award_decision_versions',
            'supplier_award_snapshots',
            'supplier_award_rows',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_award_rows');
        Schema::dropIfExists('supplier_award_snapshots');
        Schema::dropIfExists('supplier_award_decision_versions');
    }

    private function installConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_award_decision_versions ADD CONSTRAINT supplier_award_decision_version_check CHECK (decision_version > 0)');
        DB::statement("ALTER TABLE supplier_award_decision_versions ADD CONSTRAINT supplier_award_reason_check CHECK (is_lowest_price_selected OR NULLIF(BTRIM(decision_reason), '') IS NOT NULL)");
        DB::statement("ALTER TABLE supplier_award_snapshots ADD CONSTRAINT supplier_award_quality_check CHECK (quality_status IN ('complete','partial','invalid'))");
        DB::statement("ALTER TABLE supplier_award_snapshots ADD CONSTRAINT supplier_award_reconciliation_check CHECK (reconciliation_status IN ('matched','mismatch','not_applicable'))");
        DB::statement('ALTER TABLE supplier_award_rows ADD CONSTRAINT supplier_award_counts_check CHECK (responded_count <= invited_count AND invited_count > 0)');
        DB::statement('ALTER TABLE supplier_award_rows ADD CONSTRAINT supplier_award_amounts_check CHECK (selected_amount_minor >= 0 AND cheapest_amount_minor >= 0 AND median_amount_minor >= 0 AND premium_minor >= 0)');
        DB::statement('ALTER TABLE supplier_award_rows ADD CONSTRAINT supplier_award_ratios_check CHECK (premium_ratio >= 0 AND participation_ratio >= 0 AND participation_ratio <= 1)');
        DB::statement("ALTER TABLE supplier_award_rows ADD CONSTRAINT supplier_award_row_reason_check CHECK (NOT non_lowest_selected OR NULLIF(BTRIM(decision_reason), '') IS NOT NULL)");
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
