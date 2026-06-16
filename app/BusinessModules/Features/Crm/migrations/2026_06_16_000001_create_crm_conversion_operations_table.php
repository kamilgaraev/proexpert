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
        Schema::create('crm_conversion_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->uuid('crm_deal_id');
            $table->uuid('tender_id')->nullable();
            $table->uuid('commercial_proposal_id')->nullable();
            $table->string('payload_hash', 128);
            $table->string('preview_hash', 128)->nullable();
            $table->string('status', 32)->default('started');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->jsonb('result_snapshot')->default(DB::raw("'{}'::jsonb"));
            $table->string('error_code', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('crm_deal_id')->references('id')->on('crm_deals')->restrictOnDelete();
            $table->foreign('tender_id')->references('id')->on('tenders')->nullOnDelete();
            $table->foreign('commercial_proposal_id', 'crm_conversion_cp_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->nullOnDelete();

            $table->unique(['organization_id', 'idempotency_key'], 'crm_conversion_idempotency_unique');
            $table->index(['organization_id', 'crm_deal_id'], 'crm_conversion_deal_idx');
            $table->index(['organization_id', 'status'], 'crm_conversion_status_idx');
        });

        DB::statement(
            "CREATE UNIQUE INDEX crm_conversion_completed_deal_unique
            ON crm_conversion_operations (organization_id, crm_deal_id)
            WHERE status = 'completed'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_conversion_operations');
    }
};
