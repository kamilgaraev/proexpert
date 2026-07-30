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
        self::assertStringContainsString("'revision' => (int) \$generation->revision", $generation);
        self::assertStringNotContainsString('LOCK TABLE', $generation);
        self::assertStringNotContainsString('chunkById', $generation);
        self::assertStringContainsString('ownerCutoff($this->organizationId, $this->sourceCode)', $job);
        self::assertStringContainsString("':missing_target:'", $job);
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
