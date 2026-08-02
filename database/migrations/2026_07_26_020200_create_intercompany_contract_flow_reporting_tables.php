<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intercompany_contract_flow_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('holding_id');
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 64);
            $table->string('source_schema_version', 64);
            $table->string('hierarchy_watermark', 64);
            $table->string('allocation_watermark', 64);
            $table->char('quality_gap_watermark', 64);
            $table->unsignedInteger('quality_gap_count');
            $table->dateTimeTz('recorded_cutoff');
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->string('quality_status', 16);
            $table->string('freshness_status', 16);
            $table->unsignedInteger('row_count');
            $table->dateTimeTz('generated_at');
            $table->dateTimeTz('stale_at')->nullable();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'holding_id', 'query_hash', 'generated_at'], 'intercompany_flow_snapshot_lookup');
        });

        Schema::create('intercompany_contract_flow_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->ulid('snapshot_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('counterparty_organization_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('period_start');
            $table->bigInteger('internal_minor');
            $table->bigInteger('external_minor');
            $table->bigInteger('unclassified_minor');
            $table->bigInteger('total_minor');
            $table->decimal('internal_share', 20, 8)->nullable();
            $table->decimal('external_share', 20, 8)->nullable();
            $table->decimal('unclassified_share', 20, 8)->nullable();
            $table->bigInteger('linked_spread_minor')->nullable();
            $table->string('row_key', 256);
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'intercompany_flow_row_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'counterparty_organization_id', 'currency', 'period_start', 'row_key'],
                'intercompany_flow_page',
            );
            $table->foreign('snapshot_id')->references('id')->on('intercompany_contract_flow_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intercompany_contract_flow_rows');
        Schema::dropIfExists('intercompany_contract_flow_snapshots');
    }
};
