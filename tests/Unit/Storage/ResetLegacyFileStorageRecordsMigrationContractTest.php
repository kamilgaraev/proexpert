<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class ResetLegacyFileStorageRecordsMigrationContractTest extends TestCase
{
    public function test_down_restores_and_validates_the_original_report_and_quality_constraints(): void
    {
        $source = $this->migrationSource();
        $reportConstraint = "ALTER TABLE report_exports ADD CONSTRAINT report_exports_artifact_check CHECK ((status IN ('ready','expired') AND artifact_path IS NOT NULL AND artifact_version_id IS NOT NULL AND length(artifact_version_id) BETWEEN 1 AND 255 AND artifact_etag IS NOT NULL AND length(artifact_etag) BETWEEN 1 AND 255 AND artifact_etag !~ '[[:cntrl:]]' AND artifact_mime IS NOT NULL AND length(artifact_mime) BETWEEN 1 AND 255 AND artifact_checksum IS NOT NULL AND artifact_size_bytes > 0 AND row_count IS NOT NULL AND ready_at IS NOT NULL) OR (status NOT IN ('ready','expired') AND artifact_path IS NULL AND artifact_version_id IS NULL AND artifact_etag IS NULL AND artifact_mime IS NULL AND artifact_checksum IS NULL AND artifact_size_bytes IS NULL AND row_count IS NULL AND ready_at IS NULL))";
        $qualityConstraint = "ALTER TABLE quality_defect_photos ADD CONSTRAINT quality_defect_photo_storage_identity_check CHECK ((NOT storage_identity_verified AND storage_version_id IS NULL AND storage_etag IS NULL AND storage_sha256 IS NULL AND size_bytes IS NULL AND mime_type IS NULL) OR (storage_identity_verified AND url LIKE 'org-%/%' AND url NOT LIKE '%://%' AND storage_version_id IS NOT NULL AND storage_etag IS NOT NULL AND storage_sha256 ~ '^[a-f0-9]{64}$' AND size_bytes > 0 AND mime_type IS NOT NULL))";

        self::assertStringContainsString($reportConstraint, $source);
        self::assertStringContainsString(
            'ALTER TABLE report_exports VALIDATE CONSTRAINT report_exports_artifact_check',
            $source,
        );
        self::assertStringContainsString($qualityConstraint, $source);
        self::assertStringContainsString(
            'ALTER TABLE quality_defect_photos VALIDATE CONSTRAINT quality_defect_photo_storage_identity_check',
            $source,
        );
    }

    public function test_current_legal_artifact_schema_adds_etag_and_down_removes_it(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("\$table->string('storage_etag', 255)->nullable();", $source);
        self::assertStringContainsString("\$table->dropColumn('storage_etag');", $source);
    }

    private function migrationSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_06_000200_reset_legacy_file_storage_records.php',
        );
        self::assertIsString($source);

        return $source;
    }
}
