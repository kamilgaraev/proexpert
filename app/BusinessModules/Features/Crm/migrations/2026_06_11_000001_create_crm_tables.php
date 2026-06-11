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
        Schema::create('crm_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label');
            $table->string('channel_type', 64);
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('crm_pipelines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label');
            $table->string('entity_type', 32)->default('deal');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code', 'entity_type']);
            $table->index(['organization_id', 'entity_type', 'is_active']);
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->string('code', 64);
            $table->string('label');
            $table->string('category', 32)->default('open');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('probability_percent')->nullable();
            $table->jsonb('required_fields')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('required_links')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('crm_pipelines')->cascadeOnDelete();
            $table->unique(['pipeline_id', 'code']);
            $table->index(['pipeline_id', 'sort_order']);
        });

        Schema::create('crm_companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('linked_contractor_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->uuid('source_id')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->string('source_ref_type', 64)->nullable();
            $table->string('source_ref_id', 128)->nullable();
            $table->text('name');
            $table->text('legal_name')->nullable();
            $table->string('company_type', 32)->default('legal_entity');
            $table->jsonb('roles')->default(DB::raw("'[]'::jsonb"));
            $table->string('status', 32)->default('new');
            $table->string('inn', 32)->nullable();
            $table->string('kpp', 32)->nullable();
            $table->string('ogrn', 32)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('legal_address')->nullable();
            $table->text('actual_address')->nullable();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('custom_fields')->default(DB::raw("'{}'::jsonb"));
            $table->text('notes')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('source_id')->references('id')->on('crm_sources')->nullOnDelete();
            $table->foreign('merged_into_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'linked_contractor_id']);
            $table->index(['organization_id', 'source_id']);
            $table->index(['organization_id', 'merged_into_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX crm_companies_org_inn_unique ON crm_companies (organization_id, inn) WHERE inn IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('source_id')->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->string('source_ref_type', 64)->nullable();
            $table->string('source_ref_id', 128)->nullable();
            $table->text('full_name');
            $table->string('position')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->jsonb('messengers')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestampTz('personal_data_consent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->foreign('source_id')->references('id')->on('crm_sources')->nullOnDelete();
            $table->foreign('merged_into_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'company_id']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'source_id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX crm_contacts_primary_unique ON crm_contacts (organization_id, company_id) WHERE is_primary = true AND company_id IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::create('crm_contact_points', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->string('point_type', 32);
            $table->string('label')->nullable();
            $table->text('value');
            $table->text('normalized_value')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
            $table->index(['organization_id', 'point_type']);
            $table->index(['organization_id', 'normalized_value']);
        });

        Schema::create('crm_contact_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->string('identity_type', 32);
            $table->text('value');
            $table->text('normalized_value')->nullable();
            $table->string('source', 64)->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
            $table->index(['organization_id', 'identity_type']);
            $table->index(['organization_id', 'normalized_value']);
        });

        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('source_id')->nullable();
            $table->uuid('converted_deal_id')->nullable();
            $table->string('source_ref_type', 64)->nullable();
            $table->string('source_ref_id', 128)->nullable();
            $table->text('title');
            $table->string('status', 32)->default('new');
            $table->string('priority', 32)->default('normal');
            $table->decimal('estimated_amount', 18, 2)->nullable();
            $table->date('expected_start_date')->nullable();
            $table->text('need_description')->nullable();
            $table->jsonb('utm')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('raw_source_data')->default(DB::raw("'{}'::jsonb"));
            $table->text('lost_reason')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('source_id')->references('id')->on('crm_sources')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'source_id']);
        });

        Schema::create('crm_deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('company_id');
            $table->uuid('primary_contact_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->uuid('pipeline_id')->nullable();
            $table->uuid('stage_id')->nullable();
            $table->uuid('source_id')->nullable();
            $table->text('title');
            $table->string('pipeline_code', 64)->default('default');
            $table->string('stage_code', 64)->default('new');
            $table->string('status', 32)->default('open');
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->unsignedSmallInteger('probability')->nullable();
            $table->date('expected_close_at')->nullable();
            $table->timestampTz('won_at')->nullable();
            $table->timestampTz('lost_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->timestampTz('next_activity_at')->nullable();
            $table->jsonb('custom_fields')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->restrictOnDelete();
            $table->foreign('primary_contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('crm_leads')->nullOnDelete();
            $table->foreign('pipeline_id')->references('id')->on('crm_pipelines')->nullOnDelete();
            $table->foreign('stage_id')->references('id')->on('crm_pipeline_stages')->nullOnDelete();
            $table->foreign('source_id')->references('id')->on('crm_sources')->nullOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'pipeline_code', 'stage_code']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'contract_id']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->foreign('converted_deal_id')->references('id')->on('crm_deals')->nullOnDelete();
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('company_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->string('type', 32);
            $table->string('direction', 32)->nullable();
            $table->string('status', 32)->default('planned');
            $table->text('subject');
            $table->text('body')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('crm_leads')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('crm_deals')->nullOnDelete();
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'company_id']);
            $table->index(['organization_id', 'deal_id']);
        });

        Schema::create('crm_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 32)->default('companies');
            $table->string('source_format', 16);
            $table->string('status', 32)->default('previewed');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('blocked_rows')->default(0);
            $table->unsignedSmallInteger('progress_percent')->default(0);
            $table->jsonb('mapping')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('summary')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'entity_type']);
        });

        Schema::create('crm_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->unsignedInteger('row_number');
            $table->jsonb('raw_values')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('normalized_values')->default(DB::raw("'{}'::jsonb"));
            $table->string('decision', 32)->default('create');
            $table->string('status', 32)->default('accepted');
            $table->jsonb('validation_errors')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('validation_warnings')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('duplicate_candidates')->default(DB::raw("'[]'::jsonb"));
            $table->uuid('created_entity_id')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('crm_import_batches')->cascadeOnDelete();
            $table->unique(['batch_id', 'row_number']);
            $table->index(['batch_id', 'status']);
            $table->index(['batch_id', 'decision']);
        });

        Schema::create('crm_merge_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 32);
            $table->uuid('master_id');
            $table->uuid('duplicate_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('before')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('after')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('affected_links')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'entity_type']);
            $table->index(['master_id']);
            $table->index(['duplicate_id']);
        });

        Schema::create('crm_timeline_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 32);
            $table->uuid('entity_id');
            $table->string('event_type', 64);
            $table->text('summary');
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'crm_timeline_entity_idx');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_timeline_events');
        Schema::dropIfExists('crm_merge_events');
        Schema::dropIfExists('crm_import_rows');
        Schema::dropIfExists('crm_import_batches');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_contact_identities');
        Schema::dropIfExists('crm_contact_points');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_companies');
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
        Schema::dropIfExists('crm_sources');
    }
};
