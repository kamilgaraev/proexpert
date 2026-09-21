<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_document_legal_archive_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('executive_document_version_id');
            $table->unsignedBigInteger('legal_archive_document_version_id');
            $table->string('source_content_hash', 64);
            $table->timestampsTz();

            $table->foreign('executive_document_version_id', 'exec_doc_la_source_fk')
                ->references('id')->on('executive_document_versions')->restrictOnDelete();
            $table->foreign('legal_archive_document_version_id', 'exec_doc_la_archive_fk')
                ->references('id')->on('legal_archive_document_versions')->restrictOnDelete();

            $table->unique(['organization_id', 'executive_document_version_id'], 'exec_doc_la_source_version_unique');
            $table->unique('legal_archive_document_version_id', 'exec_doc_la_archive_version_unique');
            $table->index(['organization_id', 'legal_archive_document_version_id'], 'exec_doc_la_org_archive_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_document_legal_archive_versions');
    }
};
