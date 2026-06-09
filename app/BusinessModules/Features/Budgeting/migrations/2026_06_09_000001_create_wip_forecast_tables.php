<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wip_forecast_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->foreignId('budget_version_id')->nullable()->constrained('budget_versions')->nullOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('budget_scenarios')->nullOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('wip_forecast_versions')->nullOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('editing')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('as_of_date');
            $table->string('currency', 3)->nullable();
            $table->jsonb('group_by')->nullable();
            $table->string('source_snapshot_hash', 128)->nullable();
            $table->jsonb('source_snapshot')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('formulas')->nullable();
            $table->jsonb('source_coverage')->nullable();
            $table->jsonb('freshness')->nullable();
            $table->jsonb('actions')->nullable();
            $table->jsonb('meta')->nullable();
            $table->jsonb('workflow_history')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'version_number']);
            $table->index(['organization_id', 'status', 'as_of_date']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('wip_forecast_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('forecast_version_id')->constrained('wip_forecast_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->index();
            $table->foreignId('project_id')->nullable()->index();
            $table->foreignId('stage_id')->nullable()->index();
            $table->foreignId('contract_id')->nullable()->index();
            $table->foreignId('estimate_item_id')->nullable()->index();
            $table->string('period', 7)->nullable()->index();
            $table->string('currency', 3)->default('RUB');
            $table->decimal('bac', 18, 2)->default(0);
            $table->decimal('percent_complete', 8, 4)->nullable();
            $table->decimal('ev', 18, 2)->default(0);
            $table->decimal('pv', 18, 2)->default(0);
            $table->decimal('ac', 18, 2)->default(0);
            $table->decimal('wip_total', 18, 2)->default(0);
            $table->decimal('ctc', 18, 2)->default(0);
            $table->decimal('etc', 18, 2)->default(0);
            $table->decimal('ftc', 18, 2)->default(0);
            $table->decimal('eac', 18, 2)->default(0);
            $table->decimal('forecast_revenue_at_completion', 18, 2)->default(0);
            $table->decimal('forecast_gross_margin', 18, 2)->default(0);
            $table->decimal('forecast_margin_percent', 8, 4)->nullable();
            $table->decimal('cpi', 12, 6)->nullable();
            $table->decimal('spi', 12, 6)->nullable();
            $table->string('progress_source', 64)->nullable();
            $table->string('quality_status', 32)->default('actual');
            $table->jsonb('group_values')->nullable();
            $table->jsonb('dimensions')->nullable();
            $table->jsonb('problem_flags')->nullable();
            $table->jsonb('risk_flags')->nullable();
            $table->jsonb('source_row_refs')->nullable();
            $table->jsonb('formula_components')->nullable();
            $table->jsonb('comparison')->nullable();
            $table->string('source_snapshot_hash', 128)->nullable();
            $table->timestampsTz();

            $table->index(['forecast_version_id', 'currency']);
            $table->index(['organization_id', 'period', 'currency']);
        });

        Schema::create('wip_forecast_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('forecast_version_id')->constrained('wip_forecast_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->index();
            $table->string('scope', 32)->default('line');
            $table->string('scope_id', 128)->nullable();
            $table->foreignId('project_id')->nullable()->index();
            $table->foreignId('stage_id')->nullable()->index();
            $table->foreignId('contract_id')->nullable()->index();
            $table->foreignId('estimate_item_id')->nullable()->index();
            $table->string('period', 7)->nullable();
            $table->string('adjustment_type', 32)->default('cost');
            $table->string('formula_component', 64)->default('ftc');
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('percent', 8, 4)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->text('reason');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('approved')->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->jsonb('affects_formulas')->nullable();
            $table->string('source_snapshot_hash', 128)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['forecast_version_id', 'status']);
            $table->index(['organization_id', 'period', 'currency']);
        });

        Schema::create('wip_forecast_assumptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('forecast_version_id')->constrained('wip_forecast_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->index();
            $table->string('assumption_type', 64);
            $table->string('scope', 32)->default('forecast');
            $table->string('scope_id', 128)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('percent', 8, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('valid_until')->nullable();
            $table->jsonb('source_row_refs')->nullable();
            $table->string('source_snapshot_hash', 128)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('wip_forecast_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('forecast_version_id')->constrained('wip_forecast_versions')->cascadeOnDelete();
            $table->foreignId('organization_id')->index();
            $table->string('event_type', 64)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('source_snapshot_hash', 128)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['forecast_version_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wip_forecast_audit_events');
        Schema::dropIfExists('wip_forecast_assumptions');
        Schema::dropIfExists('wip_forecast_adjustments');
        Schema::dropIfExists('wip_forecast_lines');
        Schema::dropIfExists('wip_forecast_versions');
    }
};
