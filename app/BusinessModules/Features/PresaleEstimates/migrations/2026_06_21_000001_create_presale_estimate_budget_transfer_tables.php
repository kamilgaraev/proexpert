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
        Schema::create('presale_estimates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('current_version_id')->nullable()->index();
            $table->uuid('accepted_version_id')->nullable()->index();
            $table->uuid('crm_deal_id')->nullable()->index();
            $table->uuid('tender_id')->nullable()->index();
            $table->uuid('commercial_proposal_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('number', 64);
            $table->string('title');
            $table->string('status', 32)->default('draft')->index();
            $table->decimal('subtotal_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('vat_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->default($this->jsonbDefault('{}'));
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('crm_deal_id')->references('id')->on('crm_deals')->nullOnDelete();
            $table->foreign('tender_id')->references('id')->on('tenders')->nullOnDelete();
            $table->foreign('commercial_proposal_id', 'presale_estimates_cp_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();

            $table->unique(['organization_id', 'number'], 'presale_estimates_org_number_unique');
            $table->index(['organization_id', 'status'], 'presale_estimates_org_status_idx');
        });

        Schema::create('presale_estimate_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('presale_estimate_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 32)->default('draft')->index();
            $table->string('title');
            $table->jsonb('sections_snapshot')->default($this->jsonbDefault('[]'));
            $table->jsonb('totals_snapshot')->default($this->jsonbDefault('{}'));
            $table->string('content_hash', 128)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('presale_estimate_id', 'presale_versions_estimate_fk')
                ->references('id')
                ->on('presale_estimates')
                ->cascadeOnDelete();
            $table->unique(['presale_estimate_id', 'version_number'], 'presale_versions_number_unique');
            $table->index(['organization_id', 'presale_estimate_id'], 'presale_versions_org_estimate_idx');
        });

        Schema::table('presale_estimates', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'presale_current_version_fk')
                ->references('id')
                ->on('presale_estimate_versions')
                ->nullOnDelete();
            $table->foreign('accepted_version_id', 'presale_accepted_version_fk')
                ->references('id')
                ->on('presale_estimate_versions')
                ->nullOnDelete();
        });

        Schema::create('presale_estimate_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('presale_estimate_id');
            $table->uuid('presale_estimate_version_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default($this->jsonbDefault('{}'));
            $table->timestampsTz();

            $table->foreign('presale_estimate_id', 'presale_sections_estimate_fk')
                ->references('id')
                ->on('presale_estimates')
                ->cascadeOnDelete();
            $table->foreign('presale_estimate_version_id', 'presale_sections_version_fk')
                ->references('id')
                ->on('presale_estimate_versions')
                ->cascadeOnDelete();
            $table->index(['presale_estimate_version_id', 'sort_order'], 'presale_sections_version_sort_idx');
        });

        Schema::create('presale_estimate_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('presale_estimate_id');
            $table->uuid('presale_estimate_version_id');
            $table->uuid('presale_estimate_section_id')->nullable();
            $table->foreignId('budget_article_id')->nullable()->constrained('budget_articles')->nullOnDelete();
            $table->foreignId('responsibility_center_id')->nullable()->constrained('responsibility_centers')->nullOnDelete();
            $table->date('planned_month')->nullable();
            $table->string('line_type', 32)->default('work');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('vat_rate', 6, 2)->nullable();
            $table->decimal('subtotal_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default($this->jsonbDefault('{}'));
            $table->timestampsTz();

            $table->foreign('presale_estimate_id', 'presale_items_estimate_fk')
                ->references('id')
                ->on('presale_estimates')
                ->cascadeOnDelete();
            $table->foreign('presale_estimate_version_id', 'presale_items_version_fk')
                ->references('id')
                ->on('presale_estimate_versions')
                ->cascadeOnDelete();
            $table->foreign('presale_estimate_section_id', 'presale_items_section_fk')
                ->references('id')
                ->on('presale_estimate_sections')
                ->nullOnDelete();
            $table->index(['presale_estimate_version_id', 'sort_order'], 'presale_items_version_sort_idx');
        });

        Schema::create('presale_estimate_budget_transfer_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->uuid('presale_estimate_id')->nullable();
            $table->uuid('presale_estimate_version_id')->nullable();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->foreignId('budget_version_id')->nullable()->constrained('budget_versions')->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('payload_hash', 128);
            $table->string('preview_hash', 128)->nullable();
            $table->string('status', 32)->default('started');
            $table->jsonb('result_snapshot')->default($this->jsonbDefault('{}'));
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('presale_estimate_id', 'presale_transfer_estimate_fk')
                ->references('id')
                ->on('presale_estimates')
                ->nullOnDelete();
            $table->foreign('presale_estimate_version_id', 'presale_transfer_version_fk')
                ->references('id')
                ->on('presale_estimate_versions')
                ->nullOnDelete();
            $table->unique(['organization_id', 'idempotency_key'], 'presale_transfer_idempotency_unique');
            $table->index(['organization_id', 'source_type', 'source_id'], 'presale_transfer_source_idx');
            $table->index(['organization_id', 'status'], 'presale_transfer_status_idx');
        });

        DB::statement(
            "CREATE UNIQUE INDEX presale_transfer_completed_target_unique
            ON presale_estimate_budget_transfer_operations (organization_id, source_type, source_id, project_id, budget_version_id)
            WHERE status = 'completed' AND budget_version_id IS NOT NULL"
        );

        DB::statement(
            "CREATE UNIQUE INDEX presale_transfer_completed_source_project_unique
            ON presale_estimate_budget_transfer_operations (organization_id, source_type, source_id, project_id, contract_id)
            WHERE status = 'completed'"
        );
    }

    private function jsonbDefault(string $json): mixed
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return DB::raw("'" . str_replace("'", "''", $json) . "'::jsonb");
        }

        return $json;
    }

    public function down(): void
    {
        Schema::dropIfExists('presale_estimate_budget_transfer_operations');
        Schema::dropIfExists('presale_estimate_line_items');
        Schema::dropIfExists('presale_estimate_sections');
        Schema::table('presale_estimates', function (Blueprint $table): void {
            $table->dropForeign('presale_current_version_fk');
            $table->dropForeign('presale_accepted_version_fk');
        });
        Schema::dropIfExists('presale_estimate_versions');
        Schema::dropIfExists('presale_estimates');
    }
};
