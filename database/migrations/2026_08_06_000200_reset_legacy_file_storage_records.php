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
        if (Schema::hasTable('report_files')) {
            DB::table('report_files')->delete();
        }

        $this->resetReportExports();
        $this->resetQualityStorageReferences();
        $this->resetLegalStorageState();
        $this->resetEstimateStorageReferences();
    }

    public function down(): void
    {
        $this->restoreEstimateVersionStructure();
        $this->restoreLegalVersionStructure();
        $this->restoreQualityVersionStructure();
        $this->restoreReportVersionStructure();
    }

    private function resetReportExports(): void
    {
        if (! Schema::hasTable('report_exports')) {
            return;
        }

        DB::table('report_exports')->delete();
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_artifact_check');
        Schema::table('report_exports', static function (Blueprint $table): void {
            $table->dropColumn('artifact_version_id');
        });
        DB::statement(<<<'SQL'
ALTER TABLE report_exports ADD CONSTRAINT report_exports_artifact_check CHECK (
    (status IN ('ready','expired')
        AND artifact_path IS NOT NULL
        AND artifact_etag IS NOT NULL
        AND length(artifact_etag) BETWEEN 1 AND 255
        AND artifact_etag !~ '[[:cntrl:]]'
        AND artifact_mime IS NOT NULL
        AND length(artifact_mime) BETWEEN 1 AND 255
        AND artifact_checksum IS NOT NULL
        AND artifact_size_bytes > 0
        AND row_count IS NOT NULL
        AND ready_at IS NOT NULL)
    OR
    (status NOT IN ('ready','expired')
        AND artifact_path IS NULL
        AND artifact_etag IS NULL
        AND artifact_mime IS NULL
        AND artifact_checksum IS NULL
        AND artifact_size_bytes IS NULL
        AND row_count IS NULL
        AND ready_at IS NULL)
)
SQL);
    }

    private function resetQualityStorageReferences(): void
    {
        if (! Schema::hasTable('quality_defect_photos')) {
            return;
        }

        DB::statement('ALTER TABLE quality_defect_photos DROP CONSTRAINT IF EXISTS quality_defect_photo_storage_identity_check');
        DB::table('quality_defect_photos')->update([
            'storage_etag' => null,
            'storage_sha256' => null,
            'size_bytes' => null,
            'mime_type' => null,
            'storage_identity_verified' => false,
        ]);
        Schema::table('quality_defect_photos', static function (Blueprint $table): void {
            $table->dropColumn('storage_version_id');
        });
        DB::statement(<<<'SQL'
ALTER TABLE quality_defect_photos ADD CONSTRAINT quality_defect_photo_storage_identity_check CHECK (
    (NOT storage_identity_verified
        AND storage_etag IS NULL
        AND storage_sha256 IS NULL
        AND size_bytes IS NULL
        AND mime_type IS NULL)
    OR
    (storage_identity_verified
        AND url LIKE 'org-%/%'
        AND url NOT LIKE '%://%'
        AND storage_etag IS NOT NULL
        AND storage_sha256 ~ '^[a-f0-9]{64}$'
        AND size_bytes > 0
        AND mime_type IS NOT NULL)
)
SQL);
    }

    private function resetLegalStorageState(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS legal_signature_artifact_guard ON legal_signature_artifacts');
        DB::statement('DROP FUNCTION IF EXISTS legal_signature_artifact_guard()');

        if (Schema::hasTable('legal_signature_artifacts')) {
            DB::table('legal_signature_artifacts')->delete();
        }
        if (Schema::hasTable('legal_archive_file_cleanup_debts')) {
            DB::table('legal_archive_file_cleanup_debts')->delete();
        }

        DB::statement('DROP INDEX IF EXISTS legal_signature_cleanup_debts_due_idx');
        DB::statement('DROP TRIGGER IF EXISTS legal_signature_cleanup_debt_key_fill ON legal_archive_file_cleanup_debts');
        DB::statement('DROP FUNCTION IF EXISTS legal_signature_cleanup_debt_key_fill()');
        DB::statement('ALTER TABLE legal_document_signatures DROP CONSTRAINT IF EXISTS legal_document_signatures_typed_evidence_check');
        DB::statement('ALTER TABLE legal_signature_artifacts DROP CONSTRAINT IF EXISTS legal_signature_artifacts_reference_check');

        Schema::table('legal_document_signatures', static function (Blueprint $table): void {
            $table->dropColumn('storage_version_id');
        });
        Schema::table('legal_signature_artifacts', static function (Blueprint $table): void {
            $table->dropColumn('storage_version_id');
            $table->string('storage_etag', 255)->nullable();
        });
        Schema::table('legal_archive_file_cleanup_debts', static function (Blueprint $table): void {
            $table->dropColumn('storage_version_id');
        });

        DB::statement(<<<'SQL'
ALTER TABLE legal_document_signatures ADD CONSTRAINT legal_document_signatures_typed_evidence_check CHECK (
    signature_kind IN ('paper_original','detached_cades','embedded_cades','xml_dsig')
    AND time_source IN ('provider','trusted_timestamp','certificate','operator')
    AND NULLIF(btrim(diagnostic_code), '') IS NOT NULL
    AND pg_column_size(certificate_metadata) <= 16384
    AND pg_column_size(provider_metadata) <= 65536
    AND (
        (method = 'paper' AND container_format IS NULL AND certificate_fingerprint IS NULL)
        OR
        (method <> 'paper'
            AND container_format IN ('p7s','p7m','sig','xml')
            AND detected_mime_type IS NOT NULL
            AND (verification_status = 'pending_verification'
                OR (certificate_fingerprint IS NOT NULL
                    AND certificate_valid_from < certificate_valid_until
                    AND signed_at BETWEEN certificate_valid_from AND certificate_valid_until)))
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE legal_signature_artifacts ADD CONSTRAINT legal_signature_artifacts_reference_check CHECK (
    (state IN ('uploading','uploaded','ambiguous','confirmed_absent','deleting','deleted') AND referenced_signature_id IS NULL)
    OR (state = 'referenced' AND referenced_signature_id IS NOT NULL)
)
SQL);
        $this->createCurrentLegalSignatureArtifactGuard();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION legal_signature_cleanup_debt_key_fill() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.debt_key := encode(pg_catalog.sha256(pg_catalog.convert_to(
        NEW.organization_id::text || ':' || pg_catalog.octet_length(pg_catalog.convert_to(NEW.storage_path, 'UTF8'))::text || ':' || NEW.storage_path,
        'UTF8'
    )), 'hex');
    RETURN NEW;
END;
$$;
CREATE TRIGGER legal_signature_cleanup_debt_key_fill
BEFORE INSERT OR UPDATE OF organization_id, storage_path ON legal_archive_file_cleanup_debts
FOR EACH ROW EXECUTE FUNCTION legal_signature_cleanup_debt_key_fill();
SQL);
        DB::statement("CREATE INDEX legal_signature_cleanup_debts_due_idx ON legal_archive_file_cleanup_debts (next_attempt_at, id) WHERE resolved_at IS NULL AND dead_lettered_at IS NULL AND reason = 'signature_registration_failed'");
    }

    private function resetEstimateStorageReferences(): void
    {
        $this->resetEstimatePipelineStorageReferences();

        if (Schema::hasTable('estimate_generation_processing_units')) {
            DB::statement('ALTER TABLE estimate_generation_processing_units DROP CONSTRAINT IF EXISTS eg_units_locator_provenance_ck');
            DB::statement("UPDATE estimate_generation_processing_units SET locator = locator - 'artifact_version_id' - 'geometry_artifact_version_id'");
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_locator_provenance_ck CHECK (
    locator ?& ARRAY['source_kind', 'source_version', 'coordinate_space', 'artifact_path', 'artifact_sha256']
    AND jsonb_typeof(locator->'source_kind') = 'string'
    AND jsonb_typeof(locator->'source_version') = 'string'
    AND jsonb_typeof(locator->'coordinate_space') = 'string'
    AND jsonb_typeof(locator->'artifact_path') = 'string'
    AND jsonb_typeof(locator->'artifact_sha256') = 'string'
    AND ((unit_type = 'pdf_page' AND locator->>'source_kind' = 'pdf' AND locator->>'coordinate_space' = 'pdf_page_pixels')
        OR (unit_type = 'spreadsheet_sheet' AND locator->>'source_kind' = 'spreadsheet' AND locator->>'coordinate_space' = 'spreadsheet_cells')
        OR (unit_type IN ('raster_image', 'sketch') AND locator->>'source_kind' = 'image' AND locator->>'coordinate_space' = 'image_pixels')
        OR (unit_type = 'cad_drawing' AND locator->>'source_kind' = 'cad' AND locator->>'coordinate_space' = 'cad_model')
        OR (unit_type = 'text_page' AND locator->>'source_kind' = 'text' AND locator->>'coordinate_space' = 'text_offsets'))
    AND locator->>'source_version' = source_version
    AND char_length(locator->>'artifact_path') BETWEEN 1 AND 2048
    AND locator->>'artifact_sha256' ~ '^sha256:[a-f0-9]{64}$'
) NOT VALID
SQL);
        }

        if (Schema::hasTable('estimate_generation_benchmark_runs')) {
            DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_closed_state_chk');
            Schema::table('estimate_generation_benchmark_runs', static function (Blueprint $table): void {
                $table->dropColumn(['case_results_version', 'case_results_version_scheme']);
            });
            DB::statement($this->benchmarkStateConstraint());
        }
    }

    private function resetEstimatePipelineStorageReferences(): void
    {
        if (! Schema::hasTable('estimate_generation_pipeline_checkpoints')) {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS eg_checkpoint_immutable_update ON estimate_generation_pipeline_checkpoints');
        DB::statement(<<<'SQL'
UPDATE estimate_generation_pipeline_checkpoints
SET output_payload = output_payload #- '{artifact,version_id}',
    status = CASE WHEN status = 'completed' THEN 'invalidated' ELSE status END,
    invalidated_at = CASE WHEN status = 'completed' THEN CURRENT_TIMESTAMP ELSE invalidated_at END,
    invalidation_reason = CASE WHEN status = 'completed' THEN 'dependency_changed' ELSE invalidation_reason END,
    updated_at = CURRENT_TIMESTAMP
WHERE status IN ('completed','invalidated')
    AND jsonb_typeof(output_payload->'artifact') = 'object'
    AND (output_payload->'artifact') ? 'version_id'
SQL);
        DB::statement('CREATE TRIGGER eg_checkpoint_immutable_update BEFORE UPDATE ON estimate_generation_pipeline_checkpoints FOR EACH ROW EXECUTE FUNCTION eg_checkpoint_immutable_guard()');
    }

    private function restoreReportVersionStructure(): void
    {
        if (! Schema::hasTable('report_exports')) {
            return;
        }
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS report_exports_artifact_check');
        Schema::table('report_exports', static function (Blueprint $table): void {
            $table->text('artifact_version_id')->nullable();
        });
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_artifact_check CHECK ((status IN ('ready','expired') AND artifact_path IS NOT NULL AND artifact_version_id IS NOT NULL AND length(artifact_version_id) BETWEEN 1 AND 255 AND artifact_etag IS NOT NULL AND length(artifact_etag) BETWEEN 1 AND 255 AND artifact_etag !~ '[[:cntrl:]]' AND artifact_mime IS NOT NULL AND length(artifact_mime) BETWEEN 1 AND 255 AND artifact_checksum IS NOT NULL AND artifact_size_bytes > 0 AND row_count IS NOT NULL AND ready_at IS NOT NULL) OR (status NOT IN ('ready','expired') AND artifact_path IS NULL AND artifact_version_id IS NULL AND artifact_etag IS NULL AND artifact_mime IS NULL AND artifact_checksum IS NULL AND artifact_size_bytes IS NULL AND row_count IS NULL AND ready_at IS NULL))");
        DB::statement('ALTER TABLE report_exports VALIDATE CONSTRAINT report_exports_artifact_check');
    }

    private function restoreQualityVersionStructure(): void
    {
        if (! Schema::hasTable('quality_defect_photos')) {
            return;
        }
        DB::statement('ALTER TABLE quality_defect_photos DROP CONSTRAINT IF EXISTS quality_defect_photo_storage_identity_check');
        Schema::table('quality_defect_photos', static function (Blueprint $table): void {
            $table->string('storage_version_id', 255)->nullable();
        });
        DB::statement("ALTER TABLE quality_defect_photos ADD CONSTRAINT quality_defect_photo_storage_identity_check CHECK ((NOT storage_identity_verified AND storage_version_id IS NULL AND storage_etag IS NULL AND storage_sha256 IS NULL AND size_bytes IS NULL AND mime_type IS NULL) OR (storage_identity_verified AND url LIKE 'org-%/%' AND url NOT LIKE '%://%' AND storage_version_id IS NOT NULL AND storage_etag IS NOT NULL AND storage_sha256 ~ '^[a-f0-9]{64}$' AND size_bytes > 0 AND mime_type IS NOT NULL))");
        DB::statement('ALTER TABLE quality_defect_photos VALIDATE CONSTRAINT quality_defect_photo_storage_identity_check');
    }

    private function restoreLegalVersionStructure(): void
    {
        DB::statement('DROP INDEX IF EXISTS legal_signature_cleanup_debts_due_idx');
        DB::statement('DROP TRIGGER IF EXISTS legal_signature_artifact_guard ON legal_signature_artifacts');
        DB::statement('DROP FUNCTION IF EXISTS legal_signature_artifact_guard()');
        DB::statement('DROP TRIGGER IF EXISTS legal_signature_cleanup_debt_key_fill ON legal_archive_file_cleanup_debts');
        DB::statement('DROP FUNCTION IF EXISTS legal_signature_cleanup_debt_key_fill()');
        DB::statement('ALTER TABLE legal_document_signatures DROP CONSTRAINT IF EXISTS legal_document_signatures_typed_evidence_check');
        DB::statement('ALTER TABLE legal_signature_artifacts DROP CONSTRAINT IF EXISTS legal_signature_artifacts_reference_check');
        Schema::table('legal_document_signatures', static function (Blueprint $table): void {
            $table->text('storage_version_id')->nullable();
        });
        Schema::table('legal_signature_artifacts', static function (Blueprint $table): void {
            $table->dropColumn('storage_etag');
            $table->text('storage_version_id')->nullable();
        });
        Schema::table('legal_archive_file_cleanup_debts', static function (Blueprint $table): void {
            $table->text('storage_version_id')->nullable();
        });
        DB::statement("ALTER TABLE legal_document_signatures ADD CONSTRAINT legal_document_signatures_typed_evidence_check CHECK (signature_kind IN ('paper_original','detached_cades','embedded_cades','xml_dsig') AND time_source IN ('provider','trusted_timestamp','certificate','operator') AND NULLIF(btrim(diagnostic_code), '') IS NOT NULL AND pg_column_size(certificate_metadata) <= 16384 AND pg_column_size(provider_metadata) <= 65536 AND ((method = 'paper' AND container_format IS NULL AND certificate_fingerprint IS NULL) OR (method <> 'paper' AND container_format IN ('p7s','p7m','sig','xml') AND storage_version_id IS NOT NULL AND detected_mime_type IS NOT NULL AND (verification_status = 'pending_verification' OR (certificate_fingerprint IS NOT NULL AND certificate_valid_from < certificate_valid_until AND signed_at BETWEEN certificate_valid_from AND certificate_valid_until))))) NOT VALID");
        DB::statement("ALTER TABLE legal_signature_artifacts ADD CONSTRAINT legal_signature_artifacts_reference_check CHECK ((state IN ('uploading','ambiguous','confirmed_absent') AND storage_version_id IS NULL AND referenced_signature_id IS NULL) OR (state = 'referenced' AND storage_version_id IS NOT NULL AND referenced_signature_id IS NOT NULL) OR (state IN ('uploaded','deleting','deleted') AND storage_version_id IS NOT NULL AND referenced_signature_id IS NULL)) NOT VALID");
        $this->createLegacyLegalSignatureArtifactGuard();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION legal_signature_cleanup_debt_key_fill() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.debt_key := encode(pg_catalog.sha256(pg_catalog.convert_to(
        NEW.organization_id::text || ':' || pg_catalog.octet_length(pg_catalog.convert_to(NEW.storage_path, 'UTF8'))::text || ':' || NEW.storage_path || ':' ||
        pg_catalog.octet_length(pg_catalog.convert_to(COALESCE(NEW.storage_version_id, 'legacy'), 'UTF8'))::text || ':' || COALESCE(NEW.storage_version_id, 'legacy'),
        'UTF8'
    )), 'hex');
    RETURN NEW;
END;
$$;
CREATE TRIGGER legal_signature_cleanup_debt_key_fill
BEFORE INSERT OR UPDATE OF organization_id, storage_path, storage_version_id ON legal_archive_file_cleanup_debts
FOR EACH ROW EXECUTE FUNCTION legal_signature_cleanup_debt_key_fill();
SQL);
        DB::statement("CREATE INDEX legal_signature_cleanup_debts_due_idx ON legal_archive_file_cleanup_debts (next_attempt_at, id) WHERE resolved_at IS NULL AND dead_lettered_at IS NULL AND reason = 'signature_registration_failed' AND storage_version_id IS NOT NULL");
    }

    private function createCurrentLegalSignatureArtifactGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION legal_signature_artifact_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_signature_artifact_delete_forbidden'; END IF;
    IF (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.signature_request_id,
        OLD.artifact_key, OLD.storage_path, OLD.content_hash, OLD.put_request_hash, OLD.created_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.signature_request_id,
        NEW.artifact_key, NEW.storage_path, NEW.content_hash, NEW.put_request_hash, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_signature_artifact_identity_update_forbidden';
    END IF;
    IF OLD.cleanup_owned AND NOT NEW.cleanup_owned THEN
        RAISE EXCEPTION 'legal_signature_artifact_cleanup_owner_update_forbidden';
    END IF;
    IF NEW.state IN ('referenced','deleted','confirmed_absent') AND (NEW.upload_lease_token_hash IS NOT NULL OR NEW.upload_lease_expires_at IS NOT NULL
        OR NEW.deletion_lease_token_hash IS NOT NULL OR NEW.deletion_lease_expires_at IS NOT NULL) THEN
        RAISE EXCEPTION 'legal_signature_artifact_deleted_lease_forbidden';
    END IF;
    IF NOT ((OLD.state = 'uploading' AND NEW.state IN ('uploading','uploaded','ambiguous'))
        OR (OLD.state = 'ambiguous' AND NEW.state IN ('ambiguous','uploaded','referenced','confirmed_absent'))
        OR (OLD.state = 'confirmed_absent' AND NEW.state IN ('confirmed_absent','uploading'))
        OR (OLD.state = 'uploaded' AND NEW.state IN ('uploaded','referenced','deleting'))
        OR (OLD.state = 'referenced' AND NEW.state = 'referenced')
        OR (OLD.state = 'deleting' AND NEW.state IN ('deleting','referenced','deleted'))
        OR (OLD.state = 'deleted' AND NEW.state = 'deleted')) THEN
        RAISE EXCEPTION 'legal_signature_artifact_transition_forbidden';
    END IF;
    IF OLD.state = 'deleted' AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'legal_signature_artifact_terminal_update_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
CREATE TRIGGER legal_signature_artifact_guard BEFORE UPDATE OR DELETE ON legal_signature_artifacts
FOR EACH ROW EXECUTE FUNCTION legal_signature_artifact_guard();
SQL);
    }

    private function createLegacyLegalSignatureArtifactGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION legal_signature_artifact_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_signature_artifact_delete_forbidden'; END IF;
    IF (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.signature_request_id,
        OLD.artifact_key, OLD.storage_path, OLD.content_hash, OLD.put_request_hash, OLD.created_at)
       IS DISTINCT FROM
       (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.signature_request_id,
        NEW.artifact_key, NEW.storage_path, NEW.content_hash, NEW.put_request_hash, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_signature_artifact_identity_update_forbidden';
    END IF;
    IF OLD.storage_version_id IS DISTINCT FROM NEW.storage_version_id
       AND NOT (OLD.state IN ('uploading','ambiguous') AND NEW.state = 'uploaded' AND OLD.storage_version_id IS NULL AND NEW.storage_version_id IS NOT NULL) THEN
        RAISE EXCEPTION 'legal_signature_artifact_version_update_forbidden';
    END IF;
    IF OLD.cleanup_owned AND NOT NEW.cleanup_owned THEN
        RAISE EXCEPTION 'legal_signature_artifact_cleanup_owner_update_forbidden';
    END IF;
    IF NEW.state IN ('referenced','deleted','confirmed_absent') AND (NEW.upload_lease_token_hash IS NOT NULL OR NEW.upload_lease_expires_at IS NOT NULL
        OR NEW.deletion_lease_token_hash IS NOT NULL OR NEW.deletion_lease_expires_at IS NOT NULL) THEN
        RAISE EXCEPTION 'legal_signature_artifact_deleted_lease_forbidden';
    END IF;
    IF NOT ((OLD.state = 'uploading' AND NEW.state IN ('uploading','uploaded','ambiguous'))
        OR (OLD.state = 'ambiguous' AND NEW.state IN ('ambiguous','uploaded','referenced','confirmed_absent'))
        OR (OLD.state = 'confirmed_absent' AND NEW.state IN ('confirmed_absent','uploading'))
        OR (OLD.state = 'uploaded' AND NEW.state IN ('uploaded','referenced','deleting'))
        OR (OLD.state = 'referenced' AND NEW.state = 'referenced')
        OR (OLD.state = 'deleting' AND NEW.state IN ('deleting','referenced','deleted'))
        OR (OLD.state = 'deleted' AND NEW.state = 'deleted')) THEN
        RAISE EXCEPTION 'legal_signature_artifact_transition_forbidden';
    END IF;
    IF OLD.state = 'deleted' AND OLD IS DISTINCT FROM NEW THEN
        RAISE EXCEPTION 'legal_signature_artifact_terminal_update_forbidden';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
CREATE TRIGGER legal_signature_artifact_guard BEFORE UPDATE OR DELETE ON legal_signature_artifacts
FOR EACH ROW EXECUTE FUNCTION legal_signature_artifact_guard();
SQL);
    }

    private function restoreEstimateVersionStructure(): void
    {
        if (Schema::hasTable('estimate_generation_processing_units')) {
            DB::statement('ALTER TABLE estimate_generation_processing_units DROP CONSTRAINT IF EXISTS eg_units_locator_provenance_ck');
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_locator_provenance_ck CHECK (
    locator ?& ARRAY['source_kind', 'source_version', 'coordinate_space', 'artifact_path', 'artifact_sha256', 'artifact_version_id']
    AND locator->>'source_version' = source_version
    AND char_length(locator->>'artifact_path') BETWEEN 1 AND 2048
    AND locator->>'artifact_sha256' ~ '^sha256:[a-f0-9]{64}$'
    AND locator->>'artifact_version_id' ~ '^[!-~]{1,255}$'
) NOT VALID
SQL);
        }
        if (Schema::hasTable('estimate_generation_benchmark_runs')) {
            DB::statement('ALTER TABLE estimate_generation_benchmark_runs DROP CONSTRAINT IF EXISTS eg_benchmark_closed_state_chk');
            Schema::table('estimate_generation_benchmark_runs', static function (Blueprint $table): void {
                $table->text('case_results_version')->nullable();
                $table->text('case_results_version_scheme')->nullable();
            });
            DB::statement(str_replace(
                'case_results_content_type = \'application/json\'',
                "case_results_content_type = 'application/json' AND case_results_version IS NOT NULL AND case_results_version_scheme = 'provider+sha256'",
                $this->benchmarkStateConstraint(),
            ));
        }
    }

    private function benchmarkStateConstraint(): string
    {
        return <<<'SQL'
ALTER TABLE estimate_generation_benchmark_runs ADD CONSTRAINT eg_benchmark_closed_state_chk CHECK (
    (status = 'running' AND completed_at IS NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_disk IS NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_content_type IS NULL AND duration_ms IS NULL AND failure_code IS NULL AND error_summary IS NULL AND cost_amount = 0)
    OR (status = 'completed' AND completed_at IS NOT NULL AND metrics IS NOT NULL AND duration_ms >= 0 AND failure_code IS NULL AND error_summary IS NULL AND cost_amount >= 0 AND (
        (case_results IS NOT NULL AND case_results_storage_path IS NULL AND case_results_size IS NULL AND case_results_sha256 IS NULL AND case_results_etag IS NULL AND case_results_content_type IS NULL)
        OR (case_results IS NULL AND case_results_storage_disk = 's3' AND case_results_storage_path IS NOT NULL AND case_results_size > 0 AND case_results_sha256 ~ '^[a-f0-9]{64}$' AND case_results_storage_path LIKE ('%/' || case_results_sha256 || '.json') AND case_results_content_type = 'application/json')
    ))
    OR (status = 'failed' AND completed_at IS NOT NULL AND failure_code IS NOT NULL AND metrics IS NULL AND case_results IS NULL AND case_results_storage_path IS NULL AND duration_ms IS NULL AND cost_amount = 0)
) NOT VALID
SQL;
    }
};
