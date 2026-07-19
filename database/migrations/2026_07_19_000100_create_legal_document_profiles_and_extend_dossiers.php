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
        Schema::create('legal_archive_document_type_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 128);
            $table->string('base_code', 128);
            $table->string('name', 255);
            $table->jsonb('schema');
            $table->jsonb('required_fields');
            $table->jsonb('required_file_roles');
            $table->boolean('requires_signature')->nullable();
            $table->uuid('workflow_template_id')->nullable();
            $table->string('retention_policy', 128)->nullable();
            $table->string('confidentiality_level', 32)->default('internal');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'code'], 'legal_doc_profiles_org_code_unique');
            $table->index(['organization_id', 'is_active'], 'legal_doc_profiles_org_active_idx');
            $table->index(['organization_id', 'base_code'], 'legal_doc_profiles_org_base_idx');
        });

        Schema::table('legal_archive_documents', function (Blueprint $table): void {
            $table->string('type_profile_code', 128)->nullable();
            $table->string('lifecycle_status', 32)->nullable();
            $table->string('approval_status', 32)->nullable();
            $table->string('signature_status', 32)->nullable();
            $table->string('confidentiality_level', 32)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('current_primary_version_id')
                ->nullable()
                ->constrained('legal_archive_document_versions')
                ->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->jsonb('structured_fields')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->string('source_type', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('source_idempotency_key', 191)->nullable();
            $table->softDeletesTz();

            $table->index(['organization_id', 'type_profile_code'], 'legal_docs_org_profile_idx');
            $table->index(['organization_id', 'lifecycle_status'], 'legal_docs_org_lifecycle_idx');
            $table->index(['organization_id', 'approval_status'], 'legal_docs_org_approval_idx');
            $table->index(['organization_id', 'signature_status'], 'legal_docs_org_signature_idx');
            $table->index(['organization_id', 'owner_user_id'], 'legal_docs_org_owner_idx');
            $table->index(['organization_id', 'responsible_user_id'], 'legal_docs_org_responsible_idx');
            $table->index(['organization_id', 'source_type', 'source_id'], 'legal_docs_org_source_idx');
            $table->unique(
                ['organization_id', 'source_type', 'source_idempotency_key'],
                'legal_docs_source_idempotency_unique',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE legal_archive_document_type_profiles '.
                'ADD CONSTRAINT legal_doc_profiles_lock_version_check CHECK (lock_version >= 0), '.
                'ADD CONSTRAINT legal_doc_profiles_confidentiality_check '.
                "CHECK (confidentiality_level IN ('public', 'internal', 'restricted', 'secret'))"
            );

            DB::statement(
                'ALTER TABLE legal_archive_documents '.
                'ADD CONSTRAINT legal_docs_lifecycle_status_check CHECK (lifecycle_status IS NULL OR lifecycle_status IN '.
                "('draft', 'under_review', 'revision_required', 'rejected', 'approved', 'signing', ".
                "'partially_signed', 'signed', 'signature_failed', 'effective', 'suspended', 'completed', ".
                "'terminated', 'expired', 'archived')), ".
                'ADD CONSTRAINT legal_docs_approval_status_check CHECK (approval_status IS NULL OR approval_status IN '.
                "('not_started', 'pending', 'approved', 'rejected', 'revision_required', 'cancelled', 'expired')), ".
                'ADD CONSTRAINT legal_docs_signature_status_check CHECK (signature_status IS NULL OR signature_status IN '.
                "('not_required', 'unsigned', 'pending', 'partially_signed', 'signed', 'verification_failed', ".
                "'rejected', 'expired', 'revoked')), ".
                'ADD CONSTRAINT legal_docs_lock_version_check CHECK (lock_version >= 0), '.
                'ADD CONSTRAINT legal_docs_source_identity_check CHECK '.
                '(((source_type IS NULL AND source_id IS NULL) OR (source_type IS NOT NULL AND source_id IS NOT NULL)) '.
                'AND (source_idempotency_key IS NULL OR (source_type IS NOT NULL AND source_id IS NOT NULL)))'
            );
        }
    }

    public function down(): void
    {
        Schema::table('legal_archive_documents', function (Blueprint $table): void {
            $table->dropForeign(['owner_user_id']);
            $table->dropForeign(['responsible_user_id']);
            $table->dropForeign(['current_primary_version_id']);
            $table->dropUnique('legal_docs_source_idempotency_unique');
            $table->dropIndex('legal_docs_org_profile_idx');
            $table->dropIndex('legal_docs_org_lifecycle_idx');
            $table->dropIndex('legal_docs_org_approval_idx');
            $table->dropIndex('legal_docs_org_signature_idx');
            $table->dropIndex('legal_docs_org_owner_idx');
            $table->dropIndex('legal_docs_org_responsible_idx');
            $table->dropIndex('legal_docs_org_source_idx');
            $table->dropColumn([
                'type_profile_code',
                'lifecycle_status',
                'approval_status',
                'signature_status',
                'confidentiality_level',
                'owner_user_id',
                'responsible_user_id',
                'current_primary_version_id',
                'lock_version',
                'structured_fields',
                'activated_at',
                'completed_at',
                'terminated_at',
                'source_type',
                'source_id',
                'source_idempotency_key',
                'deleted_at',
            ]);
        });

        Schema::dropIfExists('legal_archive_document_type_profiles');
    }
};
