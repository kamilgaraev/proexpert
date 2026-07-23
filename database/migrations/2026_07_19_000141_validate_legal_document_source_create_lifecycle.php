<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            do {
                $ids = DB::table('legal_archive_documents')->whereNull('source_create_status')
                    ->orderBy('id')->limit(1000)->pluck('id')->all();
                if ($ids !== []) {
                    DB::table('legal_archive_documents')->whereIn('id', $ids)
                        ->update(['source_create_status' => 'completed']);
                }
            } while ($ids !== []);

            return;
        }
        do {
            $updated = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT id FROM legal_archive_documents WHERE source_create_status IS NULL ORDER BY id LIMIT 1000
)
UPDATE legal_archive_documents AS documents
SET source_create_status = 'completed'
FROM batch
WHERE documents.id = batch.id
SQL);
        } while ($updated > 0);
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_source_create_status_check');
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_source_create_status_not_null');
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_source_request_fingerprint_check');
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_source_failure_fingerprint_check');
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_source_create_coherence_check');
        DB::statement('ALTER TABLE legal_archive_documents ALTER COLUMN source_create_status SET NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_source_lifecycle_migrations_are_forward_only');
    }
};
