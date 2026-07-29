<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgeting_project_finance_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('report_code', 64);
            $table->char('definition_hash', 64);
            $table->string('formula_version', 64);
            $table->string('source_schema_version', 64);
            $table->char('scope_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->string('source_snapshot_kind', 64);
            $table->string('source_snapshot_id', 128);
            $table->char('source_snapshot_hash', 64);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->date('as_of')->nullable();
            $table->unsignedBigInteger('budget_version_id')->nullable();
            $table->unsignedBigInteger('forecast_version_id')->nullable();
            $table->char('closure_hash', 64)->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->string('quality_status', 16);
            $table->unsignedBigInteger('coverage_numerator')->default(0);
            $table->unsignedBigInteger('coverage_denominator')->default(0);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'report_code', 'scope_hash', 'query_hash', 'source_hash'],
                'budgeting_project_finance_snapshot_identity_unique',
            );
            $table->index(
                ['organization_id', 'report_code', 'generated_at'],
                'budgeting_project_finance_snapshot_lookup',
            );
        });

        Schema::create('budgeting_project_finance_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('report_code', 64);
            $table->string('row_key', 256);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->unsignedBigInteger('responsibility_center_id')->nullable();
            $table->string('responsibility_center_name')->nullable();
            $table->unsignedBigInteger('budget_article_id')->nullable();
            $table->string('article_name')->nullable();
            $table->unsignedBigInteger('wbs_id')->nullable();
            $table->string('wbs_code')->nullable();
            $table->unsignedBigInteger('budget_version_id')->nullable();
            $table->unsignedBigInteger('forecast_version_id')->nullable();
            $table->date('period')->nullable();
            $table->string('scenario', 64)->nullable();
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->string('tax_basis', 32)->default('unknown');
            $table->string('direction', 32)->nullable();
            $table->bigInteger('plan_revenue_minor')->nullable();
            $table->bigInteger('actual_revenue_minor')->nullable();
            $table->bigInteger('forecast_revenue_minor')->nullable();
            $table->bigInteger('plan_cost_minor')->nullable();
            $table->bigInteger('actual_cost_minor')->nullable();
            $table->bigInteger('forecast_cost_minor')->nullable();
            $table->bigInteger('margin_minor')->nullable();
            $table->decimal('margin_percent', 18, 8)->nullable();
            $table->bigInteger('plan_minor')->nullable();
            $table->bigInteger('actual_minor')->nullable();
            $table->bigInteger('committed_minor')->nullable();
            $table->bigInteger('available_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('risk', 32)->nullable();
            $table->bigInteger('bac_minor')->nullable();
            $table->bigInteger('pv_minor')->nullable();
            $table->bigInteger('ev_minor')->nullable();
            $table->bigInteger('ac_minor')->nullable();
            $table->bigInteger('wip_minor')->nullable();
            $table->bigInteger('ctc_minor')->nullable();
            $table->bigInteger('eac_minor')->nullable();
            $table->bigInteger('forecast_variance_minor')->nullable();
            $table->decimal('spi', 18, 8)->nullable();
            $table->decimal('cpi', 18, 8)->nullable();
            $table->string('quality_status', 16);
            $table->jsonb('source_refs');
            $table->timestampsTz();

            $table->foreign('snapshot_id')
                ->references('id')
                ->on('budgeting_project_finance_snapshots')
                ->cascadeOnDelete();
            $table->unique(
                ['organization_id', 'snapshot_id', 'row_key'],
                'budgeting_project_finance_row_identity_unique',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'project_name', 'row_key'],
                'budgeting_project_finance_project_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'article_name', 'row_key'],
                'budgeting_project_finance_article_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'currency', 'row_key'],
                'budgeting_project_finance_currency_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'period', 'row_key'],
                'budgeting_project_finance_period_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'margin_minor', 'row_key'],
                'budgeting_project_finance_margin_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'available_minor', 'row_key'],
                'budgeting_project_finance_available_sort',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'eac_minor', 'row_key'],
                'budgeting_project_finance_eac_sort',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgeting_project_finance_rows');
        Schema::dropIfExists('budgeting_project_finance_snapshots');
    }
};
