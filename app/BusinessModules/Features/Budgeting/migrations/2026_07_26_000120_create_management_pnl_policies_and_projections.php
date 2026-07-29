<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_pnl_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('version', 64);
            $table->string('status', 16);
            $table->jsonb('classification_rules');
            $table->jsonb('allocation_rules');
            $table->char('policy_hash', 64);
            $table->dateTimeTz('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'version'], 'management_pnl_policy_version_unique');
            $table->index(['organization_id', 'status'], 'management_pnl_policy_status_idx');
        });

        Schema::create('management_pnl_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('policy_id');
            $table->string('policy_version', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 64);
            $table->char('scope_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->jsonb('component_snapshots');
            $table->dateTimeTz('as_of');
            $table->dateTimeTz('generated_at');
            $table->dateTimeTz('stale_at');
            $table->unsignedInteger('row_count');
            $table->jsonb('totals');
            $table->unsignedInteger('coverage_numerator');
            $table->unsignedInteger('coverage_denominator');
            $table->string('quality_status', 16);
            $table->jsonb('warnings');
            $table->timestampsTz();

            $table->foreign('policy_id')->references('id')->on('management_pnl_policies');
            $table->unique(
                ['organization_id', 'policy_id', 'scope_hash', 'query_hash', 'source_hash'],
                'management_pnl_snapshot_identity_unique',
            );
        });

        Schema::create('management_pnl_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('responsibility_center_id')->nullable();
            $table->unsignedBigInteger('budget_article_id')->nullable();
            $table->date('period');
            $table->string('scenario', 64);
            $table->char('currency', 3);
            $table->bigInteger('revenue_minor');
            $table->bigInteger('direct_cost_minor');
            $table->bigInteger('gross_margin_minor');
            $table->bigInteger('operating_expense_minor');
            $table->bigInteger('operating_result_minor');
            $table->decimal('gross_margin_percent', 19, 8)->nullable();
            $table->string('policy_version', 64);
            $table->jsonb('source_refs');
            $table->timestampsTz();

            $table->foreign('snapshot_id')->references('id')->on('management_pnl_snapshots');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'management_pnl_row_identity_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'period', 'currency', 'scenario', 'row_key'],
                'management_pnl_row_page_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_pnl_rows');
        Schema::dropIfExists('management_pnl_snapshots');
        Schema::dropIfExists('management_pnl_policies');
    }
};
