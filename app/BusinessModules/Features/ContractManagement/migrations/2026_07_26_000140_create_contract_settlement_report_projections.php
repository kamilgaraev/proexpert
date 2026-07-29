<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_settlement_source_facts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('direction', 16);
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->unsignedBigInteger('effective_minor');
            $table->unsignedBigInteger('accepted_minor');
            $table->unsignedBigInteger('completed_cash_minor');
            $table->date('due_at')->nullable();
            $table->jsonb('source_refs');
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'source_hash', 'contract_id', 'allocation_id', 'direction', 'currency'],
                'contract_settlement_source_identity_unique',
            );
            $table->index(
                ['organization_id', 'query_hash', 'contract_id', 'allocation_id'],
                'contract_settlement_source_scope_idx',
            );
        });

        Schema::create('contract_settlement_exposure_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->string('formula_version', 64);
            $table->string('aging_policy_version', 64);
            $table->char('scope_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->dateTimeTz('as_of');
            $table->dateTimeTz('generated_at');
            $table->dateTimeTz('stale_at');
            $table->unsignedBigInteger('source_watermark_id');
            $table->unsignedInteger('row_count');
            $table->jsonb('totals');
            $table->unsignedInteger('coverage_numerator');
            $table->unsignedInteger('coverage_denominator');
            $table->string('quality_status', 16);
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'scope_hash', 'query_hash', 'source_hash'],
                'contract_settlement_snapshot_identity_unique',
            );
            $table->index(['organization_id', 'generated_at'], 'contract_settlement_snapshot_org_idx');
        });

        Schema::create('contract_settlement_exposure_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('row_key', 160);
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('direction', 16);
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->unsignedBigInteger('effective_minor');
            $table->unsignedBigInteger('accepted_minor');
            $table->unsignedBigInteger('cash_minor');
            $table->bigInteger('settlement_minor');
            $table->unsignedBigInteger('unperformed_exposure_minor');
            $table->unsignedBigInteger('unpaid_exposure_minor');
            $table->string('aging_bucket', 24);
            $table->jsonb('source_refs');
            $table->timestampsTz();

            $table->foreign('snapshot_id')->references('id')->on('contract_settlement_exposure_snapshots');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'contract_settlement_row_identity_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'unpaid_exposure_minor', 'row_key'],
                'contract_settlement_row_exposure_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'contract_id', 'allocation_id'],
                'contract_settlement_row_contract_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_settlement_exposure_rows');
        Schema::dropIfExists('contract_settlement_exposure_snapshots');
        Schema::dropIfExists('contract_settlement_source_facts');
    }
};
