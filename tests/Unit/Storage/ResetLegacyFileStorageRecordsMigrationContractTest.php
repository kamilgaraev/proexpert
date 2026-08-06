<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class ResetLegacyFileStorageRecordsMigrationContractTest extends TestCase
{
    public function test_jsonb_constraint_sql_bypasses_pdo_placeholder_parsing(): void
    {
        $source = $this->migrationSource();
        $jsonbConstraint = "DB::unprepared(<<<'SQL'\nALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_locator_provenance_ck CHECK (\n    locator ?& ARRAY";

        self::assertSame(2, substr_count($source, $jsonbConstraint));
        self::assertStringNotContainsString(
            "DB::statement(<<<'SQL'\nALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_locator_provenance_ck CHECK (\n    locator ?& ARRAY",
            $source,
        );
    }

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

    public function test_up_removes_every_runtime_reference_to_non_migrated_storage_objects(): void
    {
        $up = $this->upSource();

        foreach ([
            "DB::table('report_files')->delete();",
            "DB::table('report_exports')->delete();",
            "DB::table('quality_defect_photos')->delete();",
            "DB::table('legal_signature_artifacts')->delete();",
            "DB::table('legal_signature_verifications')->delete();",
            "DB::table('legal_document_signatures')->delete();",
            "DB::table('estimate_generation_processing_units')->delete();",
            'DELETE FROM estimate_generation_benchmark_runs WHERE case_results_storage_path IS NOT NULL',
        ] as $reset) {
            self::assertStringContainsString($reset, $up);
        }
        self::assertStringContainsString("'processing_unit_id' => null", $up);
        self::assertStringContainsString("'source_version' => null", $up);
        self::assertStringContainsString("'output_version' => null", $up);
        self::assertStringContainsString("'raw_payload_path' => null", $up);
        self::assertStringContainsString("'units_finalized_source_version' => null", $up);
        self::assertStringContainsString("'units_reconciled_source_version' => null", $up);
        self::assertStringContainsString("'units_reconcile_claim_token' => null", $up);
        self::assertStringContainsString("'units_reconcile_lease_expires_at' => null", $up);
        self::assertStringContainsString("output_payload = output_payload - 'artifact'", $up);
        self::assertStringContainsString("status = CASE WHEN status = 'completed' THEN 'invalidated'", $up);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS eg_benchmark_run_immutable', $up);
        self::assertStringContainsString('CREATE TRIGGER eg_benchmark_run_immutable', $up);
        self::assertStringNotContainsString("output_payload #- '{artifact,version_id}'", $up);
        self::assertStringNotContainsString("locator = locator - 'artifact_version_id'", $up);
    }

    public function test_reset_preserves_domain_versions_and_down_only_restores_schema(): void
    {
        $up = $this->upSource();
        $down = $this->downSource();

        self::assertStringNotContainsString("DB::table('legal_archive_document_versions')", $up);
        self::assertStringNotContainsString("DB::table('estimate_generation_training_datasets')", $up);
        self::assertStringContainsString("\$table->dropColumn(['case_results_version', 'case_results_version_scheme']);", $up);
        self::assertStringContainsString("\$table->text('case_results_version')->nullable();", $down);
        self::assertStringContainsString("\$table->text('case_results_version_scheme')->nullable();", $down);
        self::assertStringNotContainsString('DB::table(', $down);
    }

    private function upSource(): string
    {
        $source = $this->migrationSource();
        $up = explode('    public function down(): void', $source, 2)[0];
        $resetStart = strpos($source, '    private function resetReportExports(): void');
        $restoreStart = strpos($source, '    private function restoreReportVersionStructure(): void');
        self::assertNotFalse($resetStart);
        self::assertNotFalse($restoreStart);

        return $up.substr($source, $resetStart, $restoreStart - $resetStart);
    }

    private function downSource(): string
    {
        $source = $this->migrationSource();
        $parts = explode('    public function down(): void', $source, 2);
        self::assertCount(2, $parts);
        $down = explode('    private function resetReportExports(): void', $parts[1], 2)[0];
        $restoreStart = strpos($source, '    private function restoreReportVersionStructure(): void');
        self::assertNotFalse($restoreStart);

        return $down.substr($source, $restoreStart);
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
