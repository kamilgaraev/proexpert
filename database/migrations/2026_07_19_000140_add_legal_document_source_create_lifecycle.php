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
        Schema::table('legal_archive_documents', static function (Blueprint $table): void {
            $table->string('source_create_status', 16)->nullable()->default('completed');
            $table->char('source_request_fingerprint', 64)->nullable();
            $table->char('source_create_failure_fingerprint', 64)->nullable();
            $table->timestampTz('source_create_failed_at')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_source_create_status_check CHECK (source_create_status IN ('pending', 'completed', 'failed')) NOT VALID");
        DB::statement('ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_source_create_status_not_null CHECK (source_create_status IS NOT NULL) NOT VALID');
        DB::statement("ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_source_request_fingerprint_check CHECK (source_request_fingerprint IS NULL OR source_request_fingerprint ~ '^[a-f0-9]{64}$') NOT VALID");
        DB::statement("ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_source_failure_fingerprint_check CHECK (source_create_failure_fingerprint IS NULL OR source_create_failure_fingerprint ~ '^[a-f0-9]{64}$') NOT VALID");
        DB::statement("ALTER TABLE legal_archive_documents ADD CONSTRAINT legal_docs_source_create_coherence_check CHECK ((source_create_status = 'failed' AND source_create_failure_fingerprint IS NOT NULL AND source_create_failed_at IS NOT NULL) OR (source_create_status IN ('pending', 'completed') AND source_create_failure_fingerprint IS NULL AND source_create_failed_at IS NULL)) NOT VALID");
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_source_lifecycle_migrations_are_forward_only');
    }
};
