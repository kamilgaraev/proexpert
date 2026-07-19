<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        foreach ($this->postgresCreateStatements() as $indexName => $statement) {
            $this->dropInvalidPostgresIndex($indexName);
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->dropPortableIndexes();

            return;
        }

        foreach ($this->postgresIndexNames() as $indexName) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
        }
    }

    /** @return array<string, string> */
    private function postgresCreateStatements(): array
    {
        return [
            'legal_doc_profiles_org_code_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_doc_profiles_org_code_unique ON legal_archive_document_type_profiles (organization_id, code)',
            'legal_doc_profiles_org_active_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_doc_profiles_org_active_idx ON legal_archive_document_type_profiles (organization_id, is_active)',
            'legal_doc_profiles_org_base_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_doc_profiles_org_base_idx ON legal_archive_document_type_profiles (organization_id, base_code)',
            'legal_docs_org_profile_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_profile_idx ON legal_archive_documents (organization_id, type_profile_code)',
            'legal_docs_org_lifecycle_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_lifecycle_idx ON legal_archive_documents (organization_id, lifecycle_status)',
            'legal_docs_org_approval_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_approval_idx ON legal_archive_documents (organization_id, approval_status)',
            'legal_docs_org_signature_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_signature_idx ON legal_archive_documents (organization_id, signature_status)',
            'legal_docs_org_owner_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_owner_idx ON legal_archive_documents (organization_id, owner_user_id)',
            'legal_docs_org_responsible_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_responsible_idx ON legal_archive_documents (organization_id, responsible_user_id)',
            'legal_docs_org_source_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_org_source_idx ON legal_archive_documents (organization_id, source_type, source_id)',
            'legal_docs_source_idempotency_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_source_idempotency_unique ON legal_archive_documents (organization_id, source_type, source_idempotency_key)',
            'legal_archive_document_versions_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_document_versions_ownership_unique ON legal_archive_document_versions (id, document_id, organization_id)',
            'legal_docs_current_primary_ownership_idx' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_docs_current_primary_ownership_idx ON legal_archive_documents (current_primary_version_id, id, organization_id)',
        ];
    }

    /** @return list<string> */
    private function postgresIndexNames(): array
    {
        return array_reverse(array_keys($this->postgresCreateStatements()));
    }

    private function dropInvalidPostgresIndex(string $indexName): void
    {
        $invalidIndex = DB::selectOne(
            'SELECT 1 FROM pg_index AS index_state '.
            'INNER JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid '.
            'INNER JOIN pg_namespace AS index_namespace ON index_namespace.oid = index_class.relnamespace '.
            'WHERE index_namespace.nspname = current_schema() '.
            'AND index_class.relname = ? AND index_state.indisvalid = false',
            [$indexName],
        );

        if ($invalidIndex !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
        }
    }

    private function createPortableIndexes(): void
    {
        Schema::table('legal_archive_document_type_profiles', function (Blueprint $table): void {
            $table->unique(['organization_id', 'code'], 'legal_doc_profiles_org_code_unique');
            $table->index(['organization_id', 'is_active'], 'legal_doc_profiles_org_active_idx');
            $table->index(['organization_id', 'base_code'], 'legal_doc_profiles_org_base_idx');
        });
        Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
            $table->unique(['id', 'document_id', 'organization_id'], 'legal_archive_document_versions_ownership_unique');
        });
        Schema::table('legal_archive_documents', function (Blueprint $table): void {
            $table->index(['organization_id', 'type_profile_code'], 'legal_docs_org_profile_idx');
            $table->index(['organization_id', 'lifecycle_status'], 'legal_docs_org_lifecycle_idx');
            $table->index(['organization_id', 'approval_status'], 'legal_docs_org_approval_idx');
            $table->index(['organization_id', 'signature_status'], 'legal_docs_org_signature_idx');
            $table->index(['organization_id', 'owner_user_id'], 'legal_docs_org_owner_idx');
            $table->index(['organization_id', 'responsible_user_id'], 'legal_docs_org_responsible_idx');
            $table->index(['organization_id', 'source_type', 'source_id'], 'legal_docs_org_source_idx');
            $table->unique(['organization_id', 'source_type', 'source_idempotency_key'], 'legal_docs_source_idempotency_unique');
            $table->index(['current_primary_version_id', 'id', 'organization_id'], 'legal_docs_current_primary_ownership_idx');
        });
    }

    private function dropPortableIndexes(): void
    {
        Schema::table('legal_archive_documents', function (Blueprint $table): void {
            $table->dropIndex('legal_docs_current_primary_ownership_idx');
            $table->dropUnique('legal_docs_source_idempotency_unique');
            $table->dropIndex('legal_docs_org_source_idx');
            $table->dropIndex('legal_docs_org_responsible_idx');
            $table->dropIndex('legal_docs_org_owner_idx');
            $table->dropIndex('legal_docs_org_signature_idx');
            $table->dropIndex('legal_docs_org_approval_idx');
            $table->dropIndex('legal_docs_org_lifecycle_idx');
            $table->dropIndex('legal_docs_org_profile_idx');
        });
        Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
            $table->dropUnique('legal_archive_document_versions_ownership_unique');
        });
        Schema::table('legal_archive_document_type_profiles', function (Blueprint $table): void {
            $table->dropIndex('legal_doc_profiles_org_base_idx');
            $table->dropIndex('legal_doc_profiles_org_active_idx');
            $table->dropUnique('legal_doc_profiles_org_code_unique');
        });
    }
};
