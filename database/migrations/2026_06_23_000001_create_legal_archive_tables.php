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
        Schema::create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title', 512);
            $table->string('document_number')->nullable();
            $table->string('document_type', 64);
            $table->string('status', 64)->default('draft');
            $table->string('direction', 64)->default('internal');
            $table->string('source_system', 64)->default('prohelper');
            $table->string('counterparty_name')->nullable();
            $table->date('document_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('description')->nullable();
            $table->string('legal_significance_status', 64)->default('not_confirmed');
            $table->string('edo_status', 64)->nullable();
            $table->string('one_c_status', 64)->nullable();
            $table->string('retention_policy', 128)->nullable();
            $table->text('retention_basis')->nullable();
            $table->timestampTz('retention_started_at')->nullable();
            $table->timestampTz('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'document_type']);
            $table->index(['organization_id', 'document_date']);
            $table->index(['organization_id', 'counterparty_name']);
            $table->index(['organization_id', 'retention_until']);
            $table->index(['organization_id', 'legal_hold']);
            $table->index(['organization_id', 'primary_project_id']);
        });

        Schema::create('legal_archive_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('legal_archive_documents')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('version_number', 64);
            $table->string('version_label')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('status', 64)->default('uploaded');
            $table->text('file_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->string('metadata_hash', 64)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['document_id', 'version_number']);
            $table->index(['organization_id', 'uploaded_at']);
            $table->index(['document_id', 'is_current']);
        });

        Schema::create('legal_archive_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('legal_archive_documents')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('link_type', 64);
            $table->string('linked_type')->nullable();
            $table->string('linked_id')->nullable();
            $table->string('external_key')->nullable();
            $table->string('display_name');
            $table->text('url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'link_type']);
            $table->index(['linked_type', 'linked_id']);
            $table->index(['document_id', 'link_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX legal_archive_documents_search_idx ON legal_archive_documents USING gin " .
                "(to_tsvector('russian', concat_ws(' ', coalesce(title, ''), coalesce(document_number, ''), " .
                "coalesce(counterparty_name, ''), coalesce(description, ''))))"
            );

            DB::statement(
                'CREATE UNIQUE INDEX legal_archive_document_versions_current_unique ' .
                'ON legal_archive_document_versions (document_id) WHERE is_current = true'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS legal_archive_document_versions_current_unique');
            DB::statement('DROP INDEX IF EXISTS legal_archive_documents_search_idx');
        }

        Schema::dropIfExists('legal_archive_document_links');
        Schema::dropIfExists('legal_archive_document_versions');
        Schema::dropIfExists('legal_archive_documents');
    }
};
