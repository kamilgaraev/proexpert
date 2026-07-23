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

        $this->addConstraintIfMissing(
            'legal_archive_document_files',
            'legal_archive_document_files_document_fk',
            'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE CASCADE NOT VALID',
        );
        $this->addConstraintIfMissing(
            'legal_archive_document_versions',
            'legal_archive_versions_document_file_fk',
            'FOREIGN KEY (document_file_id, document_id, organization_id) REFERENCES legal_archive_document_files (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID',
        );
        $this->addConstraintIfMissing(
            'legal_archive_document_files',
            'legal_archive_document_files_current_fk',
            'FOREIGN KEY (current_version_id, id, organization_id) REFERENCES legal_archive_document_versions (id, document_file_id, organization_id) ON DELETE SET NULL (current_version_id) NOT VALID',
        );
        $this->addConstraintIfMissing(
            'legal_archive_document_versions',
            'legal_archive_versions_processing_status_check',
            "CHECK (processing_status IN ('quarantine', 'ready', 'failed')) NOT VALID",
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_archive_versions_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_archive_version_delete_forbidden';
    END IF;
    IF current_setting('most.legal_archive_version_mutation', true) IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'legal_archive_version_update_forbidden';
    END IF;
    IF OLD.status IN ('signed', 'frozen') THEN
        RAISE EXCEPTION 'legal_archive_frozen_version_update_forbidden';
    END IF;
    IF (OLD.document_id, OLD.document_file_id, OLD.organization_id, OLD.version_number, OLD.version_label,
        OLD.status, OLD.file_path, OLD.original_filename, OLD.mime_type, OLD.size_bytes, OLD.content_hash,
        OLD.metadata_hash, OLD.uploaded_by_user_id, OLD.uploaded_at, OLD.metadata, OLD.created_at)
       IS DISTINCT FROM
       (NEW.document_id, NEW.document_file_id, NEW.organization_id, NEW.version_number, NEW.version_label,
        NEW.status, NEW.file_path, NEW.original_filename, NEW.mime_type, NEW.size_bytes, NEW.content_hash,
        NEW.metadata_hash, NEW.uploaded_by_user_id, NEW.uploaded_at, NEW.metadata, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_archive_version_content_update_forbidden';
    END IF;
    IF OLD.processing_status IS DISTINCT FROM NEW.processing_status
       AND NOT (OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready', 'failed')) THEN
        RAISE EXCEPTION 'legal_archive_version_transition_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_archive_versions_immutable_guard ON legal_archive_document_versions;
CREATE TRIGGER legal_archive_versions_immutable_guard
BEFORE UPDATE OR DELETE ON legal_archive_document_versions
FOR EACH ROW EXECUTE FUNCTION legal_archive_versions_immutable_guard();
SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS legal_archive_versions_immutable_guard ON legal_archive_document_versions');
        DB::statement('DROP FUNCTION IF EXISTS legal_archive_versions_immutable_guard()');
        DB::statement('ALTER TABLE legal_archive_document_versions DROP CONSTRAINT IF EXISTS legal_archive_versions_processing_status_check');
        DB::statement('ALTER TABLE legal_archive_document_files DROP CONSTRAINT IF EXISTS legal_archive_document_files_current_fk');
        DB::statement('ALTER TABLE legal_archive_document_versions DROP CONSTRAINT IF EXISTS legal_archive_versions_document_file_fk');
        DB::statement('ALTER TABLE legal_archive_document_files DROP CONSTRAINT IF EXISTS legal_archive_document_files_document_fk');
    }

    private function addConstraintIfMissing(string $table, string $name, string $definition): void
    {
        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ?', [$name]);
        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
    }
};
