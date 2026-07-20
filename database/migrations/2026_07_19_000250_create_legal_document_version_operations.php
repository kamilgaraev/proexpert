<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legal_archive_document_version_operations')) {
            return;
        }

        Schema::create('legal_archive_document_version_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id');
            $table->string('operation_id', 191);
            $table->unsignedInteger('operation_generation')->default(1);
            $table->string('request_fingerprint', 64);
            $table->string('reserved_version_number');
            $table->boolean('make_current');
            $table->string('attempt_token', 191);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('status', 32);
            $table->text('storage_path')->nullable();
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['organization_id', 'document_file_id', 'operation_id', 'operation_generation'],
                'legal_archive_version_operation_identity_unique',
            );
            $table->unique(
                ['document_file_id', 'reserved_version_number'],
                'legal_archive_version_operation_slot_unique',
            );
            $table->index(['organization_id', 'document_id', 'status'], 'legal_archive_version_operations_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_archive_document_version_operations');
    }
};
