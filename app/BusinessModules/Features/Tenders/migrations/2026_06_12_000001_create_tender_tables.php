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
        Schema::create('tender_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label');
            $table->string('source_type', 32);
            $table->text('base_url')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
            $table->index('source_type');
        });

        Schema::create('tenders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('source_id')->nullable();
            $table->uuid('customer_company_id')->nullable();
            $table->uuid('customer_contact_id')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('crm_deal_id')->nullable();
            $table->uuid('commercial_proposal_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('number', 64);
            $table->string('external_number', 128)->nullable();
            $table->text('external_url')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('customer_name')->nullable();
            $table->string('customer_inn', 32)->nullable();
            $table->string('customer_kpp', 32)->nullable();
            $table->string('customer_ogrn', 32)->nullable();
            $table->string('status', 32)->default('incoming');
            $table->string('priority', 32)->default('normal');
            $table->string('risk_level', 32)->default('medium');
            $table->decimal('initial_max_price', 18, 2)->nullable();
            $table->text('budget_missing_reason')->nullable();
            $table->decimal('expected_bid_amount', 18, 2)->nullable();
            $table->decimal('final_bid_amount', 18, 2)->nullable();
            $table->text('final_bid_amount_missing_reason')->nullable();
            $table->decimal('winner_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('questions_deadline_at')->nullable();
            $table->timestampTz('submission_deadline_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('submission_confirmation_file_id')->nullable();
            $table->text('submission_confirmation_url')->nullable();
            $table->timestampTz('opening_at')->nullable();
            $table->timestampTz('auction_at')->nullable();
            $table->timestampTz('result_expected_at')->nullable();
            $table->timestampTz('result_published_at')->nullable();
            $table->timestampTz('next_deadline_at')->nullable();
            $table->string('go_no_go_decision', 32)->default('pending');
            $table->text('go_no_go_reason')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('winner_name')->nullable();
            $table->text('requirements_summary')->nullable();
            $table->text('analysis_summary')->nullable();
            $table->jsonb('requirements')->default('{}');
            $table->jsonb('evaluation_criteria')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('source_id')->references('id')->on('tender_sources')->nullOnDelete();
            $table->foreign('customer_company_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->foreign('customer_contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('crm_deal_id')->references('id')->on('crm_deals')->nullOnDelete();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'next_deadline_at']);
            $table->index(['organization_id', 'risk_level']);
            $table->index(['organization_id', 'source_id']);
            $table->index(['organization_id', 'crm_deal_id']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('tender_deadlines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('kind', 32);
            $table->text('title');
            $table->timestampTz('due_at');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('reminder_policy')->default('{}');
            $table->boolean('is_required')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->index(['tender_id', 'kind']);
            $table->index('due_at');
            $table->index(['responsible_user_id', 'due_at']);
        });

        Schema::create('tender_deadline_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('tender_id');
            $table->uuid('deadline_id');
            $table->string('policy_key', 64);
            $table->string('channel', 32);
            $table->timestampTz('scheduled_for');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->foreign('deadline_id')->references('id')->on('tender_deadlines')->cascadeOnDelete();
            $table->unique(['deadline_id', 'policy_key', 'channel', 'scheduled_for'], 'tender_deadline_reminders_unique');
            $table->index(['organization_id', 'status', 'scheduled_for']);
            $table->index(['tender_id', 'scheduled_for']);
        });

        Schema::create('tender_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('kind', 32);
            $table->text('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('required_for_status', 32)->nullable();
            $table->string('status', 32)->default('open');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->index(['tender_id', 'status']);
            $table->index(['owner_user_id', 'due_at']);
        });

        Schema::create('tender_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('category', 32);
            $table->text('original_name');
            $table->text('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->unique(['tender_id', 'stored_path'], 'tender_files_path_unique');
            $table->index(['tender_id', 'category']);
        });

        Schema::create('tender_competitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->uuid('crm_company_id')->nullable();
            $table->text('name');
            $table->string('inn', 32)->nullable();
            $table->string('kpp', 32)->nullable();
            $table->decimal('bid_amount', 18, 2)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('is_winner')->default(false);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->foreign('crm_company_id')->references('id')->on('crm_companies')->nullOnDelete();
            $table->index(['tender_id', 'is_winner']);
            $table->index(['tender_id', 'inn']);
        });

        Schema::create('tender_risks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tender_id');
            $table->string('kind', 32);
            $table->string('severity', 32);
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('mitigation')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->index(['tender_id', 'severity']);
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('tender_timeline_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('tender_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->text('summary');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->foreign('tender_id')->references('id')->on('tenders')->cascadeOnDelete();
            $table->index(['organization_id', 'tender_id', 'created_at'], 'tender_timeline_entity_idx');
            $table->index(['organization_id', 'event_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX tender_sources_system_code_unique ON tender_sources (code) WHERE organization_id IS NULL");
            DB::statement("CREATE UNIQUE INDEX tenders_external_number_unique ON tenders (organization_id, source_id, external_number) WHERE external_number IS NOT NULL AND deleted_at IS NULL");
            DB::statement("CREATE INDEX tenders_requirements_gin_idx ON tenders USING GIN (requirements)");
            DB::statement("CREATE INDEX tenders_evaluation_criteria_gin_idx ON tenders USING GIN (evaluation_criteria)");
            DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_status_check CHECK (status IN ('incoming', 'analysis', 'go_no_go', 'preparation', 'submitted', 'auction_waiting', 'won', 'lost', 'cancelled'))");
            DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
            DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_risk_level_check CHECK (risk_level IN ('low', 'medium', 'high', 'critical'))");
            DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_go_no_go_decision_check CHECK (go_no_go_decision IN ('pending', 'go', 'no_go'))");
            DB::statement("ALTER TABLE tender_deadlines ADD CONSTRAINT tender_deadlines_kind_check CHECK (kind IN ('publication', 'questions', 'submission', 'opening', 'auction', 'result', 'contract_signing', 'custom'))");
            DB::statement("ALTER TABLE tender_deadline_reminders ADD CONSTRAINT tender_deadline_reminders_status_check CHECK (status IN ('pending', 'sent', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE tender_requirements ADD CONSTRAINT tender_requirements_status_check CHECK (status IN ('open', 'in_progress', 'done', 'waived'))");
            DB::statement("ALTER TABLE tender_risks ADD CONSTRAINT tender_risks_severity_check CHECK (severity IN ('low', 'medium', 'high', 'critical'))");
            DB::statement("ALTER TABLE tender_risks ADD CONSTRAINT tender_risks_status_check CHECK (status IN ('open', 'mitigated', 'accepted', 'closed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_timeline_events');
        Schema::dropIfExists('tender_risks');
        Schema::dropIfExists('tender_competitors');
        Schema::dropIfExists('tender_files');
        Schema::dropIfExists('tender_requirements');
        Schema::dropIfExists('tender_deadline_reminders');
        Schema::dropIfExists('tender_deadlines');
        Schema::dropIfExists('tenders');
        Schema::dropIfExists('tender_sources');
    }
};
