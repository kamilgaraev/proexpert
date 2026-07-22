<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL most.legal_archive_version_mutation = 'service'");
            DB::statement('LOCK TABLE legal_archive_documents, legal_archive_document_files, legal_archive_document_versions IN EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
UPDATE legal_archive_document_versions AS version
SET is_current = true,
    updated_at = NOW()
FROM legal_archive_documents AS document
   , legal_archive_document_files AS file
WHERE document.current_primary_version_id = version.id
  AND file.id = version.document_file_id
  AND file.document_id = document.id
  AND file.organization_id = document.organization_id
  AND file.current_version_id = version.id
  AND file.role = 'primary'
  AND version.document_id = document.id
  AND version.organization_id = document.organization_id
  AND version.is_current = false
  AND version.processing_status = 'ready'
  AND version.status = 'uploaded'
  AND NOT EXISTS (
      SELECT 1
      FROM legal_archive_document_versions AS other
      WHERE other.document_id = version.document_id
        AND other.is_current = true
  )
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('legal_archive_current_version_flag_reconciliation_is_forward_only');
    }
};
