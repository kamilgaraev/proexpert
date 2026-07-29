<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgeting_portfolio_liquidity_source_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->char('source_version', 64);
            $table->dateTimeTz('occurred_at');
            $table->dateTimeTz('created_at');
            $table->dateTimeTz('effective_at');
            $table->jsonb('payload')->nullable();
            $table->char('source_hash', 64);
            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version'],
                'budgeting_liquidity_source_version_unique',
            );
            $table->index(
                ['organization_id', 'occurred_at', 'effective_at', 'id'],
                'budgeting_liquidity_source_as_of',
            );
        });

        Schema::create('budgeting_portfolio_report_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('report_code', 64);
            $table->dateTimeTz('as_of');
            $table->char('definition_hash', 64);
            $table->char('source_hash', 64);
            $table->char('query_hash', 64);
            $table->string('formula_version', 64);
            $table->string('source_schema_version', 64);
            $table->string('quality_status', 16);
            $table->string('freshness_status', 16);
            $table->jsonb('totals');
            $table->jsonb('watermarks');
            $table->jsonb('source_refs');
            $table->unsignedInteger('row_count');
            $table->dateTimeTz('generated_at');
            $table->dateTimeTz('stale_at')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'report_code', 'query_hash', 'generated_at'], 'budgeting_portfolio_snapshot_lookup');
        });

        Schema::create('budgeting_project_portfolio_health_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->ulid('snapshot_id');
            $table->unsignedBigInteger('project_id');
            $table->string('project_name');
            $table->char('currency', 3);
            $table->date('as_of');
            $table->unsignedSmallInteger('risk_rank');
            $table->string('risk_level', 16);
            $table->decimal('revenue', 20, 2);
            $table->decimal('cost', 20, 2);
            $table->decimal('margin', 20, 2);
            $table->decimal('margin_percent', 20, 8)->nullable();
            $table->decimal('wip', 20, 2);
            $table->decimal('ftc', 20, 2);
            $table->decimal('eac', 20, 2);
            $table->decimal('ctc', 20, 2);
            $table->string('row_key', 256);
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'budgeting_portfolio_health_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'risk_rank', 'project_id', 'currency', 'row_key'],
                'budgeting_portfolio_health_page',
            );
            $table->foreign('snapshot_id')->references('id')->on('budgeting_portfolio_report_snapshots')->restrictOnDelete();
        });

        Schema::create('budgeting_portfolio_liquidity_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->ulid('snapshot_id');
            $table->date('forecast_date');
            $table->unsignedBigInteger('project_id');
            $table->string('project_name');
            $table->char('currency', 3);
            $table->string('scenario', 64);
            $table->decimal('opening', 20, 2);
            $table->decimal('inflow', 20, 2);
            $table->decimal('outflow', 20, 2);
            $table->decimal('closing', 20, 2);
            $table->decimal('gap', 20, 2);
            $table->string('quality_status', 16);
            $table->unsignedInteger('duplicate_source_count');
            $table->jsonb('quality_gaps');
            $table->jsonb('warnings');
            $table->string('reconciliation_status', 32);
            $table->string('row_key', 256);
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'budgeting_portfolio_liquidity_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'forecast_date', 'project_id', 'currency', 'scenario', 'row_key'],
                'budgeting_portfolio_liquidity_page',
            );
            $table->foreign('snapshot_id')->references('id')->on('budgeting_portfolio_report_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgeting_portfolio_liquidity_rows');
        Schema::dropIfExists('budgeting_project_portfolio_health_rows');
        Schema::dropIfExists('budgeting_portfolio_report_snapshots');
        Schema::dropIfExists('budgeting_portfolio_liquidity_source_versions');
    }
};
