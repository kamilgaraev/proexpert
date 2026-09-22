<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_builder_drafts', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->constrained('contract_library_versions')->restrictOnDelete();
            $table->jsonb('definitions')->nullable();
            $table->jsonb('blocks')->nullable();
        });
        Schema::table('contract_builder_proposals', function (Blueprint $table): void {
            $table->foreignId('template_version_id')->nullable()->constrained('contract_library_versions')->restrictOnDelete();
        });
        Schema::create('contract_revision_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('contract_builder_revisions')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('legal_archive_documents')->restrictOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('legal_archive_document_versions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->string('content_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('last_error', 100)->nullable();
            $table->string('attempt_token', 64)->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->text('storage_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestampsTz();
            $table->unique(['revision_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revision_documents');
        Schema::table('contract_builder_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
        });
        Schema::table('contract_builder_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_version_id');
            $table->dropColumn(['definitions', 'blocks']);
        });
    }
};
