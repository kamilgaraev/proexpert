<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commercial_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('current_version_id')->nullable()->index();
            $table->uuid('accepted_version_id')->nullable()->index();
            $table->uuid('crm_deal_id')->nullable()->index();
            $table->uuid('tender_id')->nullable()->index();
            $table->uuid('presale_estimate_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('number', 64);
            $table->string('title');
            $table->string('status', 32)->default('draft')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('vat_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->date('valid_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('customer_decision_at')->nullable();
            $table->timestampTz('archived_at')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('commercial_proposal_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 32)->default('draft')->index();
            $table->string('title');
            $table->jsonb('sections_snapshot')->default('[]');
            $table->jsonb('source_links_snapshot')->default('{}');
            $table->jsonb('terms_snapshot')->default('{}');
            $table->jsonb('totals_snapshot')->default('{}');
            $table->jsonb('diff_summary')->default('{}');
            $table->string('content_hash', 128);
            $table->string('template_version_hash', 128)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('customer_decision_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_versions_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->unique(['commercial_proposal_id', 'version_number'], 'cp_versions_number_unique');
            $table->index(['organization_id', 'commercial_proposal_id']);
        });

        Schema::table('commercial_proposals', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'cp_current_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->nullOnDelete();
            $table->foreign('accepted_version_id', 'cp_accepted_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->nullOnDelete();
        });

        Schema::create('commercial_proposal_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_sections_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_sections_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->cascadeOnDelete();
            $table->index(['commercial_proposal_version_id', 'sort_order'], 'cp_sections_version_sort_idx');
        });

        Schema::create('commercial_proposal_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id');
            $table->uuid('commercial_proposal_section_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('vat_rate', 6, 2)->nullable();
            $table->decimal('subtotal_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_items_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_items_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_section_id', 'cp_items_section_fk')
                ->references('id')
                ->on('commercial_proposal_sections')
                ->nullOnDelete();
            $table->index(['commercial_proposal_version_id', 'sort_order'], 'cp_items_version_sort_idx');
        });

        Schema::create('commercial_proposal_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('body_html');
            $table->jsonb('settings')->default('{}');
            $table->string('version_hash', 128);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('commercial_proposal_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48)->default('attachment');
            $table->string('original_name');
            $table->string('storage_path', 1024);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_files_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_files_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->nullOnDelete();
            $table->index(['commercial_proposal_id', 'category']);
        });

        Schema::create('commercial_proposal_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->text('comment')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_approvals_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_approvals_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->cascadeOnDelete();
            $table->index(['commercial_proposal_id', 'status']);
        });

        Schema::create('commercial_proposal_sent_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id');
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32);
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_sent_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_sent_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->cascadeOnDelete();
            $table->index(['commercial_proposal_id', 'sent_at']);
        });

        Schema::create('commercial_proposal_timeline_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_timeline_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_timeline_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->nullOnDelete();
            $table->index(['commercial_proposal_id', 'occurred_at'], 'cp_timeline_proposal_time_idx');
        });

        Schema::create('commercial_proposal_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('commercial_proposal_id');
            $table->uuid('commercial_proposal_version_id');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 16)->default('pdf');
            $table->string('status', 32)->default('pending')->index();
            $table->string('content_hash', 128);
            $table->string('template_version_hash', 128)->nullable();
            $table->jsonb('options')->default('{}');
            $table->string('storage_path', 1024)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->foreign('commercial_proposal_id', 'cp_exports_proposal_fk')
                ->references('id')
                ->on('commercial_proposals')
                ->cascadeOnDelete();
            $table->foreign('commercial_proposal_version_id', 'cp_exports_version_fk')
                ->references('id')
                ->on('commercial_proposal_versions')
                ->cascadeOnDelete();
            $table->unique(
                ['commercial_proposal_version_id', 'format', 'content_hash', 'template_version_hash'],
                'cp_exports_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_proposal_exports');
        Schema::dropIfExists('commercial_proposal_timeline_events');
        Schema::dropIfExists('commercial_proposal_sent_events');
        Schema::dropIfExists('commercial_proposal_approvals');
        Schema::dropIfExists('commercial_proposal_files');
        Schema::dropIfExists('commercial_proposal_templates');
        Schema::dropIfExists('commercial_proposal_line_items');
        Schema::dropIfExists('commercial_proposal_sections');
        Schema::table('commercial_proposals', function (Blueprint $table): void {
            $table->dropForeign('cp_current_version_fk');
            $table->dropForeign('cp_accepted_version_fk');
        });
        Schema::dropIfExists('commercial_proposal_versions');
        Schema::dropIfExists('commercial_proposals');
    }
};
