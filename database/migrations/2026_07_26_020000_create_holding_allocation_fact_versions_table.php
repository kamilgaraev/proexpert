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
        Schema::create('holding_allocation_fact_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('holding_id');
            $table->string('hierarchy_version', 64);
            $table->unsignedBigInteger('contributor_organization_id');
            $table->unsignedBigInteger('counterparty_organization_id')->nullable();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('linked_parent_allocation_id')->nullable();
            $table->bigInteger('linked_incoming_minor')->nullable();
            $table->bigInteger('linked_outgoing_minor')->nullable();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_version');
            $table->string('source_schema_version', 64);
            $table->string('monetary_basis', 32);
            $table->string('tax_basis', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->nullable();
            $table->string('currency_source', 32);
            $table->date('recognized_on');
            $table->string('flow_class', 16);
            $table->bigInteger('allocated_amount_minor')->nullable();
            $table->decimal('allocated_percentage', 20, 8)->nullable();
            $table->bigInteger('contract_amount_minor')->nullable();
            $table->jsonb('source_refs');
            $table->char('source_hash', 64);
            $table->dateTimeTz('projected_at');
            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version', 'monetary_basis'],
                'holding_allocation_fact_source_unique',
            );
            $table->index(
                ['organization_id', 'holding_id', 'recognized_on', 'currency', 'monetary_basis', 'id'],
                'holding_allocation_fact_report_lookup',
            );
        });

        DB::statement(
            'ALTER TABLE holding_allocation_fact_versions ADD CONSTRAINT holding_allocation_method_check '
            .'CHECK ((allocated_amount_minor IS NOT NULL AND allocated_percentage IS NULL) '
            .'OR (allocated_amount_minor IS NULL AND allocated_percentage IS NOT NULL AND contract_amount_minor IS NOT NULL))',
        );
        DB::statement(
            'ALTER TABLE holding_allocation_fact_versions ADD CONSTRAINT holding_allocation_basis_check '
            ."CHECK (monetary_basis IN ('contracted', 'accepted_accrual', 'cash'))",
        );
        DB::statement(
            'ALTER TABLE holding_allocation_fact_versions ADD CONSTRAINT holding_allocation_flow_check '
            ."CHECK (flow_class IN ('internal', 'external', 'unclassified'))",
        );
        DB::statement(
            'ALTER TABLE holding_allocation_fact_versions ADD CONSTRAINT holding_allocation_currency_check '
            ."CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')",
        );
        DB::statement(
            'ALTER TABLE holding_allocation_fact_versions ADD CONSTRAINT holding_allocation_link_evidence_check '
            .'CHECK ((linked_parent_allocation_id IS NULL AND linked_incoming_minor IS NULL AND linked_outgoing_minor IS NULL) '
            .'OR (linked_parent_allocation_id IS NOT NULL AND linked_incoming_minor IS NOT NULL AND linked_outgoing_minor IS NOT NULL))',
        );

        Schema::create('holding_allocation_projection_gaps', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('holding_id');
            $table->string('hierarchy_version', 64);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_version');
            $table->string('monetary_basis', 32);
            $table->jsonb('missing_fields');
            $table->char('source_hash', 64);
            $table->dateTimeTz('observed_at');
            $table->dateTimeTz('resolved_at')->nullable();
            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version', 'monetary_basis', 'source_hash'],
                'holding_allocation_gap_source_unique',
            );
            $table->index(
                ['holding_id', 'organization_id', 'monetary_basis', 'resolved_at', 'id'],
                'holding_allocation_gap_readiness',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_allocation_projection_gaps');
        Schema::dropIfExists('holding_allocation_fact_versions');
    }
};
