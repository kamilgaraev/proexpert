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
            || ! Schema::hasTable('legal_archive_document_versions')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_archive_versions_immutable_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_archive_version_delete_forbidden'; END IF;
    IF current_setting('most.legal_archive_version_mutation', true) IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'legal_archive_version_update_forbidden';
    END IF;
    IF OLD.status IN ('signed', 'frozen') THEN RAISE EXCEPTION 'legal_archive_frozen_version_update_forbidden'; END IF;
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
       AND NOT ((OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready', 'failed'))
           OR (OLD.processing_status = 'failed' AND NEW.processing_status = 'quarantine')) THEN
        RAISE EXCEPTION 'legal_archive_version_transition_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_archive_version_rescan_rollback_blocked');
    }
};
