<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_request_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('contract_project_allocation_id')->nullable();
            $table->unsignedBigInteger('initiator_user_id')->nullable();
            $table->string('initiator_type', 64);
            $table->string('reason', 255);
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('status', 32);
            $table->bigInteger('proposed_cost_minor');
            $table->integer('proposed_schedule_days');
            $table->bigInteger('approved_cost_minor')->nullable();
            $table->integer('approved_schedule_days')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('currency_source', 64)->nullable();
            $table->dateTimeTz('effective_at');
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->unique(['organization_id', 'change_request_id', 'version'], 'change_request_version_identity_unique');
            $table->index(
                ['organization_id', 'project_id', 'effective_at', 'change_request_id', 'version'],
                'change_request_version_scope_idx',
            );
        });

        Schema::create('change_workflow_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('change_request_id');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('project_id');
            $table->string('event_type', 32);
            $table->string('prior_status', 32)->nullable();
            $table->string('current_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTimeTz('occurred_at');
            $table->char('event_hash', 64);
            $table->timestampsTz();

            $table->unique(['organization_id', 'event_hash'], 'change_workflow_event_hash_unique');
            $table->index(
                ['organization_id', 'project_id', 'occurred_at', 'change_request_id', 'version', 'id'],
                'change_workflow_event_scope_idx',
            );
        });

        Schema::create('contingency_ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_project_allocation_id');
            $table->char('currency', 3);
            $table->string('currency_source', 64);
            $table->string('movement_type', 16);
            $table->bigInteger('signed_amount_minor');
            $table->date('effective_on');
            $table->string('source_type', 64);
            $table->string('source_id', 96);
            $table->unsignedInteger('source_version');
            $table->string('idempotency_key', 128);
            $table->char('entry_hash', 64);
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'source_version', 'movement_type'],
                'contingency_ledger_source_identity_unique',
            );
            $table->unique(['organization_id', 'idempotency_key'], 'contingency_ledger_idempotency_unique');
            $table->index(
                ['organization_id', 'project_id', 'contract_project_allocation_id', 'currency', 'effective_on', 'id'],
                'contingency_ledger_scope_idx',
            );
        });

        Schema::create('change_claim_links', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('change_request_version_id');
            $table->unsignedBigInteger('change_claim_id');
            $table->unsignedInteger('claim_version');
            $table->bigInteger('claim_amount_minor');
            $table->char('currency', 3);
            $table->string('relationship_type', 32);
            $table->char('source_hash', 64);
            $table->timestampsTz();

            $table->foreign('change_request_version_id')->references('id')->on('change_request_versions');
            $table->unique(['organization_id', 'change_claim_id', 'claim_version'], 'change_claim_version_link_unique');
        });

        Schema::create('change_claim_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->string('formula_version', 64);
            $table->char('scope_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('version_watermark_id');
            $table->unsignedBigInteger('ledger_watermark_id');
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
        });

        Schema::create('change_claim_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('organization_id');
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('contract_project_allocation_id');
            $table->unsignedBigInteger('change_request_id')->nullable();
            $table->unsignedInteger('change_version')->nullable();
            $table->string('status', 32);
            $table->date('occurred_on');
            $table->char('currency', 3);
            $table->bigInteger('proposed_exposure_minor');
            $table->bigInteger('approved_exposure_minor');
            $table->bigInteger('linked_claim_minor');
            $table->bigInteger('opening_contingency_minor');
            $table->bigInteger('allocated_contingency_minor');
            $table->bigInteger('consumed_contingency_minor');
            $table->bigInteger('released_contingency_minor');
            $table->bigInteger('closing_contingency_minor');
            $table->string('quality_status', 16);
            $table->jsonb('source_refs');
            $table->timestampsTz();

            $table->foreign('snapshot_id')->references('id')->on('change_claim_snapshots');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'change_claim_row_identity_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'currency', 'status', 'occurred_on', 'row_key'],
                'change_claim_row_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_claim_rows');
        Schema::dropIfExists('change_claim_snapshots');
        Schema::dropIfExists('change_claim_links');
        Schema::dropIfExists('contingency_ledger_entries');
        Schema::dropIfExists('change_workflow_events');
        Schema::dropIfExists('change_request_versions');
    }
};
