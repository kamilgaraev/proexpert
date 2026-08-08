<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportingSourcePersistenceContractTest extends TestCase
{
    #[Test]
    public function postgres_overlap_constraint_is_partitioned_by_site(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/SafetyManagement/migrations/'
            .'2026_07_26_080000_create_workforce_admission_reporting_tables.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString(
            'workforce_assignment_id WITH =, safety_site_id WITH =, (daterange',
            $migration,
        );
    }

    #[Test]
    public function source_sync_has_a_durable_cursor_and_readiness_ledger(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/database/migrations/2026_07_30_000001_create_report_source_sync_ledgers.php',
        );
        $job = file_get_contents(dirname(__DIR__, 4).'/app/Jobs/ReportingSourceBackfillJob.php');
        $generation = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Support/ReportSourceOwnerGeneration.php',
        );

        self::assertIsString($migration);
        self::assertIsString($job);
        self::assertIsString($generation);
        self::assertStringContainsString("Schema::create('report_source_sync_ledgers'", $migration);
        self::assertStringContainsString("->jsonb('cursor')", $migration);
        self::assertStringContainsString("->jsonb('target_cursor')", $migration);
        self::assertStringContainsString("->string('completed_owner_checksum', 64)", $migration);
        self::assertStringContainsString('lockForUpdate()', $job);
        self::assertStringContainsString('ShouldBeUniqueUntilProcessing', $job);
        self::assertStringContainsString("'status' => 'pending'", $job);
        self::assertStringContainsString("'completed_owner_checksum' => null", $job);
        self::assertStringContainsString('->afterCommit()', $job);
        self::assertStringContainsString("Schema::create('report_source_generations'", $migration);
        self::assertStringContainsString('bump_report_source_generation', $migration);
        self::assertStringContainsString('safety_briefing_participants_report_source_generation', $migration);
        self::assertStringContainsString("'revision' => (int) \$generation->revision", $generation);
        self::assertStringNotContainsString('LOCK TABLE', $generation);
        self::assertStringNotContainsString('chunkById', $generation);
        self::assertStringContainsString('ownerCutoff($this->organizationId, $this->sourceCode)', $job);
        self::assertStringContainsString("':missing_target:'", $job);
    }

    #[Test]
    public function workforce_evidence_is_temporal_and_snapshot_rows_pin_an_exact_version(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/SafetyManagement/migrations/'
            .'2026_07_26_080000_create_workforce_admission_reporting_tables.php',
        );
        $materializer = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Services/'
            .'WorkforceAdmissionSnapshotMaterializer.php',
        );

        self::assertIsString($migration);
        self::assertIsString($materializer);
        self::assertStringContainsString("Schema::create('safety_evidence_versions'", $migration);
        self::assertStringContainsString('capture_safety_evidence_version', $migration);
        self::assertStringContainsString('capture_safety_briefing_evidence_version', $migration);
        self::assertStringContainsString("'evidence_version_id' => \$evidenceVersion['id']", $materializer);
        self::assertStringContainsString("'evidence_hash' => \$evidenceVersion['hash']", $materializer);
        self::assertStringContainsString('history_complete', $migration);
        self::assertStringContainsString("jsonb_build_object('_deleted', true)", $migration);
        self::assertStringContainsString("Schema::create('safety_assignment_ownership_versions'", $migration);
        self::assertStringContainsString('capture_safety_assignment_ownership', $migration);
    }

    #[Test]
    public function sealed_outputs_and_photo_storage_identity_use_a_forward_migration(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 4).'/database/migrations/2026_07_30_000002_harden_reporting_evidence_integrity.php',
        );
        $originalPhotoMigration = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/QualityControl/migrations/'
            .'2026_05_14_000002_create_quality_defect_photos_table.php',
        );

        self::assertIsString($migration);
        self::assertIsString($originalPhotoMigration);
        self::assertStringContainsString("Schema::table('quality_defect_photos'", $migration);
        self::assertStringContainsString('sealed_reporting_snapshot_guard', $migration);
        self::assertStringContainsString('sealed_reporting_row_guard', $migration);
        self::assertStringContainsString('persisted_rows = NEW.row_count', $migration);
        self::assertStringContainsString('quality_defect_flow_snapshots', $migration);
        self::assertStringContainsString('safety_incident_snapshots', $migration);
        self::assertStringContainsString('safety_admission_snapshots', $migration);
        self::assertStringNotContainsString('storage_version_id', $originalPhotoMigration);
        $drillDown = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/SafetyManagement/Reporting/Admission/DrillDown/'
            .'WorkforceAdmissionDrillDownProvider.php',
        );
        self::assertIsString($drillDown);
        self::assertStringContainsString('$row->evidence_id !== null', $drillDown);
        self::assertStringContainsString('$row->evidence_version_id !== null', $drillDown);
        self::assertStringContainsString("'briefing' => 'admin.safety_management.briefings.show'", $drillDown);
        self::assertStringContainsString("'safety_briefing'", $drillDown);
        $qualityRecorder = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Services/'
            .'QualityDefectTransitionRecorder.php',
        );
        $qualityMaterializer = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Services/'
            .'QualityDefectFlowSnapshotMaterializer.php',
        );
        self::assertIsString($qualityRecorder);
        self::assertIsString($qualityMaterializer);
        self::assertStringContainsString("['coverage'] ?? null) === 'unknown'", $qualityRecorder);
        self::assertStringContainsString("'legacy_storage_identity_unverified'", $qualityRecorder);
        self::assertStringContainsString('$hasUnknownEvidence', $qualityMaterializer);
    }

    #[Test]
    public function every_quality_snapshot_is_bound_to_its_exact_completed_ledger_generation(): void
    {
        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Services/QualityDefectFlowSnapshotMaterializer.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Services/SafetyIncidentSnapshotMaterializer.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Services/WorkforceAdmissionSnapshotMaterializer.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 4).'/'.$relativePath);
            self::assertIsString($source);
            self::assertStringContainsString('CompletedReportSourceLedgerBinding::capture', $source);
            self::assertStringContainsString('CompletedReportSourceLedgerBinding::lockAndAssertOwnerGeneration', $source);
            self::assertStringContainsString("'source_ledger_binding' => \$ledgerBinding", $source);
            self::assertStringContainsString("'source_watermark' => CarbonImmutable::parse", $source);
        }

        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Readiness/QualityDefectFlowReadinessProbe.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Readiness/SafetyIncidentReadinessProbe.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Readiness/WorkforceAdmissionReadinessProbe.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 4).'/'.$relativePath);
            self::assertIsString($source);
            self::assertStringContainsString('CompletedReportSourceLedgerBinding::matches', $source);
        }
    }

    #[Test]
    public function materializers_dispatch_chunk_jobs_without_full_scan_calls(): void
    {
        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Services/QualityDefectFlowSnapshotMaterializer.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Services/SafetyIncidentSnapshotMaterializer.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/Admission/Services/WorkforceAdmissionSnapshotMaterializer.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 4).'/'.$relativePath);
            self::assertIsString($source);
            self::assertStringContainsString('ReportingSourceBackfillJob::request', $source);
            self::assertStringNotContainsString('->synchronize(', $source);
        }
    }

    #[Test]
    public function exposure_projection_fails_closed_for_ambiguous_site_attribution(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Backfill/SafetyExposureBackfill.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('siteIdsForCorrection', $source);
        self::assertStringContainsString('$siteIds->count() !== 1', $source);
        self::assertStringContainsString('$gaps++', $source);
        self::assertStringNotContainsString("->join('safety_site_workforce_assignments as mapping'", $source);
    }

    #[Test]
    public function closing_flags_read_each_entity_current_state(): void
    {
        foreach ([
            'app/BusinessModules/Features/QualityControl/Reporting/DefectFlow/Services/QualityDefectFlowSnapshotMaterializer.php',
            'app/BusinessModules/Features/SafetyManagement/Reporting/IncidentActions/Services/SafetyIncidentSnapshotMaterializer.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 4).'/'.$relativePath);
            self::assertIsString($source);
            self::assertStringContainsString("['current_open'] ?? false", $source);
            self::assertStringNotContainsString("'closing_flag'] = \$state[", $source);
        }
    }
}
