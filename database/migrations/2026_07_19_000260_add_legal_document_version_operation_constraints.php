<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('legal_archive_document_version_operations')
        ) {
            return;
        }

        $this->add('legal_archive_version_operations_document_fk',
            'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE RESTRICT NOT VALID');
        $this->add('legal_archive_version_operations_file_fk',
            'FOREIGN KEY (document_file_id, document_id, organization_id) REFERENCES legal_archive_document_files (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID');
        $this->add('legal_archive_version_operations_version_fk',
            'FOREIGN KEY (document_version_id, document_file_id, organization_id) REFERENCES legal_archive_document_versions (id, document_file_id, organization_id) ON DELETE RESTRICT NOT VALID');
        $this->add('legal_archive_version_operations_status_check',
            "CHECK (status IN ('reserved', 'quarantine', 'completed', 'failed')) NOT VALID");
        $this->add('legal_archive_version_operations_state_check',
            "CHECK ((status = 'reserved' AND storage_path IS NULL AND document_version_id IS NULL) OR (status IN ('quarantine', 'completed', 'failed') AND storage_path IS NOT NULL AND document_version_id IS NOT NULL)) NOT VALID");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['state_check', 'status_check', 'version_fk', 'file_fk', 'document_fk'] as $suffix) {
            DB::statement("ALTER TABLE legal_archive_document_version_operations DROP CONSTRAINT IF EXISTS legal_archive_version_operations_{$suffix}");
        }
    }

    private function add(string $name, string $definition): void
    {
        if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ?', [$name]) === null) {
            DB::statement("ALTER TABLE legal_archive_document_version_operations ADD CONSTRAINT {$name} {$definition}");
        }
    }
};
