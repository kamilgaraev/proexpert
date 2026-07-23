<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_archive_document_files')) {
            Schema::create('legal_archive_document_files', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('organization_id');
                $table->string('role', 64);
                $table->string('title', 512);
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_required')->default(false);
                $table->timestampsTz();
            });
        }

        if (! Schema::hasColumn('legal_archive_document_versions', 'document_file_id')) {
            Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('document_file_id')->nullable();
            });
        }
        if (! Schema::hasColumn('legal_archive_document_versions', 'processing_status')) {
            Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
                $table->string('processing_status', 32)->default('quarantine');
            });
        }
    }

    public function down(): void
    {
        foreach (['document_file_id', 'processing_status'] as $column) {
            if (Schema::hasColumn('legal_archive_document_versions', $column)) {
                Schema::table('legal_archive_document_versions', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
        Schema::dropIfExists('legal_archive_document_files');
    }
};
