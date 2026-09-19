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
        Schema::create('contract_revision_legal_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('contract_builder_revisions')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('filename');
            $table->text('storage_path');
            $table->char('content_hash', 64);
            $table->char('file_hash', 64);
            $table->unsignedInteger('size');
            $table->foreignId('document_id')->nullable()->constrained('legal_archive_documents')->restrictOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('legal_archive_document_versions')->restrictOnDelete();
            $table->string('last_error', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['revision_id', 'organization_id'], 'contract_revision_legal_org');
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_contract_revision_legal_binding() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_revision_legal_binding_immutable'; END IF;
    IF (to_jsonb(OLD) - ARRAY['document_id','document_version_id','last_error','updated_at'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['document_id','document_version_id','last_error','updated_at'])
       OR (OLD.document_version_id IS NOT NULL AND (OLD.document_version_id IS DISTINCT FROM NEW.document_version_id OR OLD.document_id IS DISTINCT FROM NEW.document_id)) THEN
        RAISE EXCEPTION 'contract_revision_legal_binding_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_revision_legal_binding_guard BEFORE UPDATE OR DELETE ON contract_revision_legal_bindings
FOR EACH ROW EXECUTE FUNCTION guard_contract_revision_legal_binding();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revision_legal_bindings');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_revision_legal_binding();');
    }
};
