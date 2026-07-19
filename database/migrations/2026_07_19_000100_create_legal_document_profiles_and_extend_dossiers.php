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
            $table->unsignedBigInteger('organization_id');
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
        });

        Schema::table('legal_archive_documents', function (Blueprint $table): void {
            $table->string('type_profile_code', 128)->nullable();
            $table->string('lifecycle_status', 32)->nullable();
            $table->string('approval_status', 32)->nullable();
            $table->string('signature_status', 32)->nullable();
            $table->string('confidentiality_level', 32)->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->unsignedInteger('lock_version')->nullable();
            $table->jsonb('structured_fields')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->string('source_type', 128)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('source_idempotency_key', 191)->nullable();
            $table->softDeletesTz();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE legal_archive_document_type_profiles '.
            'ADD CONSTRAINT legal_doc_profiles_organization_fk FOREIGN KEY (organization_id) '.
            'REFERENCES organizations (id) ON DELETE CASCADE NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_document_type_profiles '.
            'ADD CONSTRAINT legal_doc_profiles_lock_version_check CHECK (lock_version >= 0) NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_document_type_profiles '.
            'ADD CONSTRAINT legal_doc_profiles_confidentiality_check '.
            "CHECK (confidentiality_level IN ('public', 'internal', 'restricted', 'secret')) NOT VALID"
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_owner_user_fk FOREIGN KEY (owner_user_id) '.
            'REFERENCES users (id) ON DELETE SET NULL NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_responsible_user_fk FOREIGN KEY (responsible_user_id) '.
            'REFERENCES users (id) ON DELETE SET NULL NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_lifecycle_status_check CHECK (lifecycle_status IS NULL OR lifecycle_status IN '.
            "('draft', 'under_review', 'revision_required', 'rejected', 'approved', 'signing', ".
            "'partially_signed', 'signed', 'signature_failed', 'effective', 'suspended', 'completed', ".
            "'terminated', 'expired', 'archived')) NOT VALID"
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_approval_status_check CHECK (approval_status IS NULL OR approval_status IN '.
            "('not_started', 'pending', 'approved', 'rejected', 'revision_required', 'cancelled', 'expired')) NOT VALID"
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_signature_status_check CHECK (signature_status IS NULL OR signature_status IN '.
            "('not_required', 'unsigned', 'pending', 'partially_signed', 'signed', 'verification_failed', ".
            "'rejected', 'expired', 'revoked')) NOT VALID"
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_confidentiality_check '.
            "CHECK (confidentiality_level IS NULL OR confidentiality_level IN ('public', 'internal', 'restricted', 'secret')) NOT VALID"
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_lock_version_check CHECK (lock_version IS NULL OR lock_version >= 0) NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_source_identity_check CHECK '.
            '(((source_type IS NULL AND source_id IS NULL) OR (source_type IS NOT NULL AND source_id IS NOT NULL)) '.
            'AND (source_idempotency_key IS NULL OR (source_type IS NOT NULL AND source_id IS NOT NULL))) NOT VALID'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_source_identity_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_lock_version_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_confidentiality_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_signature_status_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_approval_status_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_lifecycle_status_check');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_responsible_user_fk');
            DB::statement('ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_owner_user_fk');
        }

        Schema::table('legal_archive_documents', function (Blueprint $table): void {
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
