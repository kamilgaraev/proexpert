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

        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'ADD CONSTRAINT legal_docs_current_primary_version_fk '.
            'FOREIGN KEY (current_primary_version_id, id, organization_id) '.
            'REFERENCES legal_archive_document_versions (id, document_id, organization_id) '.
            'ON DELETE SET NULL (current_primary_version_id) NOT VALID'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE legal_archive_documents '.
                'DROP CONSTRAINT IF EXISTS legal_docs_current_primary_version_fk'
            );
        }
    }
};
