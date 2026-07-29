<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holding_performance_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('holding_id');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 64);
            $table->string('hierarchy_watermark', 64);
            $table->string('allocation_watermark', 64);
            $table->string('act_watermark', 64);
            $table->string('payment_watermark', 64);
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->string('quality_status', 16);
            $table->string('freshness_status', 16);
            $table->unsignedInteger('row_count');
            $table->dateTimeTz('generated_at');
            $table->dateTimeTz('stale_at')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'holding_id', 'query_hash', 'generated_at'], 'holding_performance_snapshot_lookup');
        });

        Schema::create('holding_performance_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->ulid('snapshot_id');
            $table->unsignedBigInteger('contributor_organization_id');
            $table->unsignedBigInteger('project_id');
            $table->char('currency', 3)->nullable();
            $table->date('period_start');
            $table->string('monetary_basis', 32);
            $table->bigInteger('contracted_minor');
            $table->bigInteger('accepted_accrual_minor');
            $table->bigInteger('cash_minor');
            $table->string('row_key', 256);
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'holding_performance_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'contributor_organization_id', 'project_id', 'currency', 'period_start', 'monetary_basis', 'row_key'],
                'holding_performance_page',
            );
            $table->foreign('snapshot_id')->references('id')->on('holding_performance_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_performance_rows');
        Schema::dropIfExists('holding_performance_snapshots');
    }
};
