<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_recertification_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('type', 40)->default('periodic');
            $table->string('status', 40)->default('draft')->index();
            $table->string('risk_mode', 40)->default('risk_based');
            $table->jsonb('scope')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('launched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snapshot_hash', 128)->nullable();
            $table->string('correlation_id', 120)->nullable()->index();
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'due_at'], 'arc_campaigns_org_status_due_idx');
        });

        Schema::create('access_recertification_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('access_recertification_campaigns')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('user_role_assignments')->nullOnDelete();
            $table->string('role_slug', 120);
            $table->string('role_type', 40)->default('system');
            $table->foreignId('role_context_id')->nullable()->constrained('authorization_contexts')->nullOnDelete();
            $table->string('role_context_type', 40)->nullable();
            $table->unsignedBigInteger('role_context_resource_id')->nullable();
            $table->string('role_context_label', 180)->nullable();
            $table->string('role_label', 180)->nullable();
            $table->jsonb('permission_snapshot')->nullable();
            $table->jsonb('risk_snapshot')->nullable();
            $table->jsonb('evidence_snapshot')->nullable();
            $table->string('assignment_snapshot_hash', 128);
            $table->string('risk_level', 40)->default('low')->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('next_review_at')->nullable();
            $table->timestampTz('last_reminder_at')->nullable();
            $table->string('correlation_id', 120)->nullable()->index();
            $table->timestampsTz();

            $table->unique(['campaign_id', 'assignment_snapshot_hash'], 'arc_items_campaign_snapshot_unique');
            $table->index(['organization_id', 'reviewer_user_id', 'status'], 'arc_items_org_reviewer_status_idx');
            $table->index(['organization_id', 'subject_user_id'], 'arc_items_org_subject_idx');
        });

        Schema::create('access_recertification_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('access_recertification_campaigns')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('access_recertification_items')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 40)->index();
            $table->text('reason');
            $table->timestampTz('valid_until')->nullable();
            $table->timestampTz('next_review_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->foreignId('revoke_executor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('evidence_notes')->nullable();
            $table->jsonb('compensating_controls')->nullable();
            $table->jsonb('linked_sod_rule_ids')->nullable();
            $table->jsonb('evidence_snapshot')->nullable();
            $table->uuid('audit_event_id')->nullable();
            $table->timestampsTz();

            $table->foreign('audit_event_id')->references('id')->on('immutable_audit_events')->nullOnDelete();
            $table->index(['organization_id', 'decision'], 'arc_decisions_org_decision_idx');
        });

        Schema::create('access_recertification_revocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('access_recertification_campaigns')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('access_recertification_items')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('user_role_assignments')->nullOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role_slug', 120);
            $table->string('role_type', 40)->default('system');
            $table->foreignId('role_context_id')->nullable()->constrained('authorization_contexts')->nullOnDelete();
            $table->string('status', 40)->default('pending')->index();
            $table->text('reason');
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->uuid('audit_event_id')->nullable();
            $table->timestampsTz();

            $table->foreign('audit_event_id')->references('id')->on('immutable_audit_events')->nullOnDelete();
            $table->unique('item_id', 'arc_revocations_item_unique');
            $table->index(['organization_id', 'status', 'due_at'], 'arc_revocations_org_status_due_idx');
        });

        Schema::create('access_recertification_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('access_recertification_campaigns')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('access_recertification_items')->cascadeOnDelete();
            $table->foreignUuid('decision_id')->nullable()->constrained('access_recertification_decisions')->nullOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 40)->default('requested')->index();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestampTz('valid_until')->index();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->jsonb('compensating_controls')->nullable();
            $table->jsonb('linked_sod_rule_ids')->nullable();
            $table->jsonb('evidence_snapshot')->nullable();
            $table->uuid('audit_event_id')->nullable();
            $table->timestampsTz();

            $table->foreign('audit_event_id')->references('id')->on('immutable_audit_events')->nullOnDelete();
            $table->index(['organization_id', 'status', 'valid_until'], 'arc_exceptions_org_status_valid_idx');
        });

        Schema::create('access_recertification_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('access_recertification_campaigns')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('completed')->index();
            $table->string('format', 20)->default('csv');
            $table->jsonb('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_path', 500)->nullable();
            $table->uuid('audit_event_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('audit_event_id')->references('id')->on('immutable_audit_events')->nullOnDelete();
            $table->index(['organization_id', 'created_at'], 'arc_exports_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_recertification_exports');
        Schema::dropIfExists('access_recertification_exceptions');
        Schema::dropIfExists('access_recertification_revocations');
        Schema::dropIfExists('access_recertification_decisions');
        Schema::dropIfExists('access_recertification_items');
        Schema::dropIfExists('access_recertification_campaigns');
    }
};
