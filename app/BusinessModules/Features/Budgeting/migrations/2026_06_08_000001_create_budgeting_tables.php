<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('period_type', 32);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 48)->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'starts_at', 'ends_at']);
        });

        Schema::create('budget_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('scenario_type', 48);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'scenario_type', 'is_active']);
        });

        Schema::create('responsibility_centers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('responsibility_centers')->nullOnDelete();
            $table->string('center_type', 48);
            $table->string('code', 96);
            $table->string('name');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('linked_entity_type', 64)->nullable();
            $table->unsignedBigInteger('linked_entity_id')->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'center_type', 'is_active']);
            $table->index(['linked_entity_type', 'linked_entity_id']);
        });

        Schema::create('budget_articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('budget_articles')->nullOnDelete();
            $table->string('code', 96);
            $table->string('name');
            $table->string('budget_kind', 32);
            $table->string('flow_direction', 32);
            $table->boolean('is_leaf')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('cost_category_id')->nullable()->constrained('cost_categories')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'budget_kind', 'flow_direction']);
            $table->index(['parent_id', 'is_active']);
        });

        Schema::create('budget_article_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_article_id')->constrained('budget_articles')->cascadeOnDelete();
            $table->string('system', 32)->default('1c');
            $table->foreignId('one_c_base_id')->constrained('one_c_bases')->cascadeOnDelete();
            $table->foreignId('integration_profile_id')->nullable()->constrained('one_c_integration_profiles')->nullOnDelete();
            $table->string('external_code', 128);
            $table->string('external_name')->nullable();
            $table->string('mapping_status', 48)->default('active');
            $table->jsonb('mapping_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'budget_article_id', 'system', 'one_c_base_id', 'integration_profile_id', 'external_code'],
                'budget_article_mappings_unique_external'
            );
            $table->index(['organization_id', 'system', 'one_c_base_id', 'integration_profile_id'], 'budget_article_mappings_integration_idx');
        });

        Schema::create('budget_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_period_id')->constrained('budget_periods')->cascadeOnDelete();
            $table->foreignId('scenario_id')->constrained('budget_scenarios')->cascadeOnDelete();
            $table->string('budget_kind', 32);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 48)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('workflow_history')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['organization_id', 'budget_period_id', 'scenario_id', 'budget_kind', 'version_number'],
                'budget_versions_unique_number'
            );
            $table->index(['organization_id', 'budget_kind', 'status']);
            $table->index(['budget_period_id', 'scenario_id']);
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_version_id')->constrained('budget_versions')->cascadeOnDelete();
            $table->foreignId('budget_article_id')->constrained('budget_articles')->restrictOnDelete();
            $table->foreignId('responsibility_center_id')->constrained('responsibility_centers')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->string('currency', 3)->default('RUB');
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_version_id', 'budget_article_id']);
            $table->index(['responsibility_center_id', 'project_id']);
        });

        Schema::create('budget_amounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('plan_amount', 18, 2)->default(0);
            $table->decimal('forecast_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->timestamps();

            $table->unique(['budget_line_id', 'month']);
            $table->index(['month', 'currency']);
        });

        Schema::create('budget_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_version_id')->nullable()->constrained('budget_versions')->cascadeOnDelete();
            $table->string('source_format', 16);
            $table->string('template_code', 64)->nullable();
            $table->string('mapping_mode', 32)->default('by_code');
            $table->string('status', 48)->default('previewed');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('preview_summary')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'budget_version_id', 'status']);
        });

        Schema::create('budget_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_import_batch_id')->constrained('budget_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_payload');
            $table->jsonb('normalized_payload')->nullable();
            $table->string('validation_status', 32)->default('valid');
            $table->jsonb('validation_errors')->nullable();
            $table->jsonb('validation_warnings')->nullable();
            $table->timestamps();

            $table->unique(['budget_import_batch_id', 'row_number']);
            $table->index(['budget_import_batch_id', 'validation_status']);
        });

        Schema::create('budget_period_closures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_period_id')->constrained('budget_periods')->cascadeOnDelete();
            $table->string('closure_status', 48);
            $table->string('closure_mode', 32)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_until')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['budget_period_id', 'closure_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_period_closures');
        Schema::dropIfExists('budget_import_rows');
        Schema::dropIfExists('budget_import_batches');
        Schema::dropIfExists('budget_amounts');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budget_versions');
        Schema::dropIfExists('budget_article_mappings');
        Schema::dropIfExists('budget_articles');
        Schema::dropIfExists('responsibility_centers');
        Schema::dropIfExists('budget_scenarios');
        Schema::dropIfExists('budget_periods');
    }
};
