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
            $this->createPortableIndexes();

            return;
        }

        foreach ($this->postgresIndexes() as $name => $statement) {
            $this->dropInvalidPostgresIndex($name);
            DB::statement($statement);
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_archive_document_versions_current_unique');
        DB::statement('ALTER TABLE legal_archive_document_versions DROP CONSTRAINT IF EXISTS legal_archive_document_versions_document_id_version_number_unique');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->dropPortableIndexes();

            return;
        }

        $this->assertLegacyRollbackCompatible();
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_versions_document_id_version_number_unique ON legal_archive_document_versions (document_id, version_number)');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_versions_current_unique ON legal_archive_document_versions (document_id) WHERE is_current = true');

        foreach (array_reverse(array_keys($this->postgresIndexes())) as $indexName) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
        }
    }

    /** @return array<string, string> */
    private function postgresIndexes(): array
    {
        return [
            'legal_archive_documents_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_documents_ownership_unique ON legal_archive_documents (id, organization_id)',
            'legal_archive_document_files_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_files_ownership_unique ON legal_archive_document_files (id, document_id, organization_id)',
            'legal_archive_document_files_org_document_role_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_files_org_document_role_unique ON legal_archive_document_files (organization_id, document_id, role)',
            'legal_archive_document_file_versions_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_file_versions_unique ON legal_archive_document_versions (document_file_id, version_number) WHERE document_file_id IS NOT NULL',
            'legal_archive_document_versions_file_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_versions_file_ownership_unique ON legal_archive_document_versions (id, document_file_id, organization_id)',
            'legal_archive_document_file_current_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_file_current_unique ON legal_archive_document_versions (document_file_id) WHERE document_file_id IS NOT NULL AND is_current = true',
            'legal_archive_document_legacy_current_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_legacy_current_unique ON legal_archive_document_versions (document_id) WHERE document_file_id IS NULL AND is_current = true',
            'legal_archive_document_files_org_document_order_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_files_org_document_order_idx ON legal_archive_document_files (organization_id, document_id, sort_order)',
        ];
    }

    private function assertLegacyRollbackCompatible(): void
    {
        $duplicateVersion = DB::selectOne(
            'SELECT 1 FROM legal_archive_document_versions GROUP BY document_id, version_number HAVING COUNT(*) > 1 LIMIT 1'
        );
        $multipleCurrent = DB::selectOne(
            'SELECT 1 FROM legal_archive_document_versions WHERE is_current = true GROUP BY document_id HAVING COUNT(*) > 1 LIMIT 1'
        );

        if ($duplicateVersion !== null || $multipleCurrent !== null) {
            throw new RuntimeException('legal_archive_version_index_rollback_incompatible');
        }
    }

    private function dropInvalidPostgresIndex(string $indexName): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index AS index_state '.
            'INNER JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '.
            'INNER JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '.
            'WHERE index_namespace.nspname = current_schema() '.
            'AND index_class.relname = ? AND index_state.indisvalid = false',
            [$indexName],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
        }
    }

    private function createPortableIndexes(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS legal_archive_document_files_ownership_unique ON legal_archive_document_files (id, document_id, organization_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS legal_archive_document_files_org_document_role_unique ON legal_archive_document_files (organization_id, document_id, role)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS legal_archive_document_file_versions_unique ON legal_archive_document_versions (document_file_id, version_number)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS legal_archive_document_versions_file_ownership_unique ON legal_archive_document_versions (id, document_file_id, organization_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS legal_archive_document_files_org_document_order_idx ON legal_archive_document_files (organization_id, document_id, sort_order)');
    }

    private function dropPortableIndexes(): void
    {
        foreach (array_reverse(array_keys($this->postgresIndexes())) as $indexName) {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }
    }
};
