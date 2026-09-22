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
        Schema::create('contract_supplementary_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->unsignedBigInteger('base_revision_id');
            $table->unsignedBigInteger('previous_document_id')->nullable();
            $table->foreignId('template_version_id')->constrained('contract_library_versions')->restrictOnDelete();
            $table->foreignId('author_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('number', 191);
            $table->date('agreement_date');
            $table->string('status', 20)->default('draft');
            $table->jsonb('frame_document');
            $table->jsonb('frame_definitions')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('revision_document');
            $table->jsonb('revision_definitions');
            $table->jsonb('parties');
            $table->jsonb('entity_snapshots')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('base_values');
            $table->jsonb('values');
            $table->char('content_hash', 64);
            $table->decimal('change_amount', 18, 2)->nullable();
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
            $table->unique(['contract_id', 'request_key'], 'contract_supplementary_request');
            $table->foreign(['base_revision_id', 'instance_id'], 'contract_supplementary_revision')
                ->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
            $table->foreign('previous_document_id', 'contract_supplementary_previous')
                ->references('id')->on('contract_supplementary_documents')->restrictOnDelete();
        });

        Schema::create('contract_supplementary_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('contract_supplementary_documents')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('values');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('contract_supplementary_draft_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('contract_supplementary_drafts')->restrictOnDelete();
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->jsonb('response');
            $table->timestampTz('created_at');
            $table->unique(['draft_id', 'request_key'], 'contract_supplementary_draft_request');
        });

        Schema::create('contract_supplementary_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('contract_supplementary_documents')->restrictOnDelete();
            $table->string('side', 8);
            $table->foreignId('party_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('recorded_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('content_hash', 64);
            $table->text('basis')->nullable();
            $table->jsonb('proof')->nullable();
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['document_id', 'side'], 'contract_supplementary_confirmation_side');
            $table->unique(['document_id', 'recorded_organization_id', 'request_key'], 'contract_supplementary_confirmation_request');
        });

        Schema::create('contract_supplementary_legal_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('contract_supplementary_documents')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('filename');
            $table->text('storage_path');
            $table->char('content_hash', 64);
            $table->char('file_hash', 64);
            $table->unsignedInteger('size');
            $table->foreignId('document_archive_id')->nullable()->constrained('legal_archive_documents')->restrictOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('legal_archive_document_versions')->restrictOnDelete();
            $table->string('last_error', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['document_id', 'organization_id'], 'contract_supplementary_legal_org');
        });

        Schema::create('contract_supplementary_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('contract_supplementary_documents')->restrictOnDelete();
            $table->unsignedBigInteger('previous_document_id')->nullable();
            $table->foreign('previous_document_id', 'contract_supplementary_activation_previous')
                ->references('id')->on('contract_supplementary_documents')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('content_hash', 64);
            $table->text('basis');
            $table->date('effective_date');
            $table->jsonb('plan');
            $table->jsonb('previous_terms');
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('scheduled');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->jsonb('result')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->text('cancellation_basis')->nullable();
            $table->string('cancellation_key', 191)->nullable();
            $table->char('cancellation_fingerprint', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['document_id', 'organization_id', 'request_key'], 'contract_supplementary_activation_request');
            $table->index(['status', 'effective_date']);
        });

        Schema::table('specifications', function (Blueprint $table): void {
            $table->foreignId('supplementary_document_id')->nullable()->unique()
                ->constrained('contract_supplementary_documents')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE contract_supplementary_documents ADD CONSTRAINT contract_supplementary_shape CHECK (
    status IN ('draft', 'applied', 'cancelled')
    AND jsonb_typeof(frame_document) = 'object' AND jsonb_typeof(frame_definitions) = 'object'
    AND jsonb_typeof(revision_document) = 'object' AND jsonb_typeof(revision_definitions) = 'object'
    AND jsonb_typeof(parties) = 'array' AND jsonb_array_length(parties) = 2
    AND jsonb_typeof(entity_snapshots) = 'object'
    AND jsonb_typeof(base_values) = 'object' AND jsonb_typeof(values) = 'object'
    AND ((status = 'applied' AND applied_at IS NOT NULL) OR (status <> 'applied' AND applied_at IS NULL))
);
CREATE UNIQUE INDEX contract_supplementary_open ON contract_supplementary_documents (contract_id)
    WHERE status NOT IN ('applied', 'cancelled');
ALTER TABLE contract_supplementary_drafts ADD CONSTRAINT contract_supplementary_draft_shape CHECK (
    version > 0 AND jsonb_typeof(values) = 'object'
);
ALTER TABLE contract_supplementary_confirmations ADD CONSTRAINT contract_supplementary_confirmation_shape CHECK (
    side IN ('first', 'second') AND (
      (kind = 'connected' AND party_organization_id IS NOT NULL AND party_organization_id = recorded_organization_id AND proof IS NULL)
      OR (kind = 'external' AND party_organization_id IS NULL AND basis IS NOT NULL AND length(trim(basis)) > 0 AND proof IS NOT NULL AND jsonb_typeof(proof) = 'object')
    )
);
CREATE FUNCTION guard_contract_supplementary_confirmation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'contract_supplementary_confirmation_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_supplementary_confirmation_guard BEFORE UPDATE OR DELETE ON contract_supplementary_confirmations
FOR EACH ROW EXECUTE FUNCTION guard_contract_supplementary_confirmation();
CREATE FUNCTION guard_contract_supplementary_legal_binding() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_supplementary_legal_binding_immutable'; END IF;
    IF (to_jsonb(OLD) - ARRAY['document_archive_id','document_version_id','last_error','updated_at'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['document_archive_id','document_version_id','last_error','updated_at'])
       OR (OLD.document_version_id IS NOT NULL AND (OLD.document_version_id IS DISTINCT FROM NEW.document_version_id OR OLD.document_archive_id IS DISTINCT FROM NEW.document_archive_id)) THEN
        RAISE EXCEPTION 'contract_supplementary_legal_binding_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_supplementary_legal_binding_guard BEFORE UPDATE OR DELETE ON contract_supplementary_legal_bindings
FOR EACH ROW EXECUTE FUNCTION guard_contract_supplementary_legal_binding();
CREATE UNIQUE INDEX contract_supplementary_activation_pending ON contract_supplementary_activations (document_id)
    WHERE status IN ('scheduled', 'failed');
CREATE UNIQUE INDEX contract_supplementary_activation_live ON contract_supplementary_activations (document_id)
    WHERE status <> 'cancelled';
ALTER TABLE contract_supplementary_activations ADD CONSTRAINT contract_supplementary_activation_shape CHECK (
    status IN ('scheduled', 'failed', 'applied', 'cancelled') AND length(trim(basis)) > 0
    AND jsonb_typeof(plan) = 'object' AND jsonb_typeof(previous_terms) = 'object'
    AND ((status = 'applied' AND applied_at IS NOT NULL AND result IS NOT NULL AND last_error IS NULL)
        OR (status IN ('scheduled', 'failed', 'cancelled') AND applied_at IS NULL AND result IS NULL))
    AND ((status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancelled_organization_id IS NOT NULL
        AND cancellation_basis IS NOT NULL AND length(trim(cancellation_basis)) > 0 AND cancellation_key IS NOT NULL AND cancellation_fingerprint IS NOT NULL)
        OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancelled_organization_id IS NULL
            AND cancellation_basis IS NULL AND cancellation_key IS NULL AND cancellation_fingerprint IS NULL))
);
CREATE FUNCTION guard_contract_supplementary_activation() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_supplementary_activation_immutable'; END IF;
    IF OLD.status IN ('applied', 'cancelled') OR (to_jsonb(OLD) - ARRAY['status','attempts','last_error','result','applied_at','updated_at','cancelled_at','cancelled_by','cancelled_organization_id','cancellation_basis','cancellation_key','cancellation_fingerprint'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status','attempts','last_error','result','applied_at','updated_at','cancelled_at','cancelled_by','cancelled_organization_id','cancellation_basis','cancellation_key','cancellation_fingerprint']) THEN
        RAISE EXCEPTION 'contract_supplementary_activation_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_supplementary_activation_guard BEFORE UPDATE OR DELETE ON contract_supplementary_activations
FOR EACH ROW EXECUTE FUNCTION guard_contract_supplementary_activation();
CREATE OR REPLACE FUNCTION guard_revision_specification() RETURNS trigger AS $$
BEGIN
    IF OLD.builder_revision_id IS NOT NULL OR OLD.supplementary_document_id IS NOT NULL THEN
        RAISE EXCEPTION 'revision_specification_immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_revision_specification() RETURNS trigger AS $$
BEGIN
    IF OLD.builder_revision_id IS NOT NULL THEN RAISE EXCEPTION 'revision_specification_immutable'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        Schema::table('specifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplementary_document_id');
        });
        Schema::dropIfExists('contract_supplementary_activations');
        Schema::dropIfExists('contract_supplementary_legal_bindings');
        Schema::dropIfExists('contract_supplementary_confirmations');
        Schema::dropIfExists('contract_supplementary_draft_operations');
        Schema::dropIfExists('contract_supplementary_drafts');
        Schema::dropIfExists('contract_supplementary_documents');
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS guard_contract_supplementary_activation();
DROP FUNCTION IF EXISTS guard_contract_supplementary_legal_binding();
DROP FUNCTION IF EXISTS guard_contract_supplementary_confirmation();
SQL);
    }
};
