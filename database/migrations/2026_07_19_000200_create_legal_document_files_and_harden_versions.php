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

        Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->string('processing_status', 32)->default('quarantine');
        });

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
        DB::statement(
            'ALTER TABLE legal_archive_document_files ADD CONSTRAINT legal_archive_document_files_document_fk '.
            'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) '.
            'ON DELETE CASCADE NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_document_versions ADD CONSTRAINT legal_archive_versions_document_file_fk '.
            'FOREIGN KEY (document_file_id, document_id, organization_id) '.
            'REFERENCES legal_archive_document_files (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_document_files ADD CONSTRAINT legal_archive_document_files_current_fk '.
            'FOREIGN KEY (current_version_id, id, organization_id) '.
            'REFERENCES legal_archive_document_versions (id, document_file_id, organization_id) '.
            'ON DELETE SET NULL (current_version_id) NOT VALID'
        );
        DB::statement(
            'ALTER TABLE legal_archive_document_versions ADD CONSTRAINT legal_archive_versions_processing_status_check '.
            "CHECK (processing_status IN ('quarantine', 'ready', 'failed')) NOT VALID"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE legal_archive_document_versions DROP CONSTRAINT IF EXISTS legal_archive_versions_processing_status_check');
            DB::statement('ALTER TABLE legal_archive_document_files DROP CONSTRAINT IF EXISTS legal_archive_document_files_current_fk');
            DB::statement('ALTER TABLE legal_archive_document_versions DROP CONSTRAINT IF EXISTS legal_archive_versions_document_file_fk');
            DB::statement('ALTER TABLE legal_archive_document_files DROP CONSTRAINT IF EXISTS legal_archive_document_files_document_fk');

            foreach (array_reverse(array_keys($this->postgresIndexes())) as $indexName) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");
            }
        } else {
            Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
                $table->dropUnique('legal_archive_document_file_versions_unique');
                $table->dropUnique('legal_archive_document_versions_file_ownership_unique');
            });
        }

        Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
            $table->dropColumn(['document_file_id', 'processing_status']);
        });
        Schema::dropIfExists('legal_archive_document_files');
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

    private function createPortableIndexes(): void
    {
        Schema::table('legal_archive_document_files', function (Blueprint $table): void {
            $table->unique(['id', 'document_id', 'organization_id'], 'legal_archive_document_files_ownership_unique');
            $table->unique(['organization_id', 'document_id', 'role'], 'legal_archive_document_files_org_document_role_unique');
            $table->index(['organization_id', 'document_id', 'sort_order'], 'legal_archive_document_files_org_document_order_idx');
        });
        Schema::table('legal_archive_document_versions', function (Blueprint $table): void {
            $table->unique(['document_file_id', 'version_number'], 'legal_archive_document_file_versions_unique');
            $table->unique(['id', 'document_file_id', 'organization_id'], 'legal_archive_document_versions_file_ownership_unique');
        });
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
};
