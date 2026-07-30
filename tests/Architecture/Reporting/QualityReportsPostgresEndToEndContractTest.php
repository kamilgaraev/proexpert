<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class QualityReportsPostgresEndToEndContractTest extends TestCase
{
    public function test_r23_r24_r25_have_an_opt_in_real_postgres_end_to_end_contract(): void
    {
        $path = dirname(__DIR__, 2).'/Integration/Reporting/Waves23/QualityReportsEndToEndPostgresTest.php';

        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            "getenv('QUALITY_REPORTS_PG_DSN')",
            'QualityDefectFlowReportProvider::class',
            'SafetyIncidentActionsReportProvider::class',
            'WorkforceAdmissionReportProvider::class',
            'ReportRunStore::class',
            'ReportRowChunkReader::class',
            'CsvReportExportRenderer::class',
            'XlsxReportExportRenderer::class',
            'ReportSnapshotSealVerifier::class',
            'MaterializeReportRunJob',
            'Bus::dispatchSync',
            'report_snapshot_seals',
            'quality-contract-v2',
            'snapshot_seal_signature',
            'SignedReportCursorCodec::class',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        foreach ([
            'ReflectionClass',
            'newInstanceWithoutConstructor',
            'FakeReportingActions',
            'sqlite',
            "'.sig'",
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_postgres_connection_allows_an_explicit_ephemeral_schema(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 3).'/config/database.php');

        self::assertStringContainsString("'search_path' => env('DB_SCHEMA', 'public')", $configuration);
    }

    public function test_snapshot_signing_is_independent_from_the_application_key(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php',
        );
        $configuration = (string) file_get_contents(dirname(__DIR__, 3).'/config/reporting.php');

        self::assertStringContainsString("config('reporting.snapshot_signing.active_private_key')", $provider);
        self::assertStringContainsString("config('reporting.snapshot_signing.active_key_id')", $provider);
        self::assertStringContainsString('ReportSnapshotSealVerifier::class', $provider);
        self::assertStringNotContainsString("CanonicalReportSnapshotSealer((string) config('app.key')", $provider);
        self::assertStringContainsString('REPORT_SNAPSHOT_TRUSTED_PUBLIC_KEYS', $configuration);
    }

    public function test_official_seals_are_persisted_once_and_revalidated_on_every_ready_read(): void
    {
        $root = dirname(__DIR__, 3);
        $store = (string) file_get_contents(
            $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        );
        $sealStore = (string) file_get_contents(
            $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportSnapshotSealStore.php',
        );
        $migration = (string) file_get_contents(
            $root.'/database/migrations/2026_07_30_000003_create_immutable_report_snapshot_seals.php',
        );

        self::assertStringContainsString('ReportSnapshotSealValidator $sealValidator', $store);
        self::assertStringContainsString('ReportSnapshotSealVerifier $sealVerifier', $store);
        self::assertGreaterThanOrEqual(2, substr_count($store, 'assertTrustedReadyRecord('));
        self::assertStringContainsString('lockForUpdate()', $sealStore);
        self::assertStringContainsString("DB::table('report_snapshot_seals')->insert", $sealStore);
        self::assertStringContainsString('report_snapshot_seal_immutable', $migration);
        self::assertStringNotContainsString('updateOrInsert', $sealStore);

        foreach ([
            'QualityControl/Reporting/DefectFlow/Providers/QualityDefectFlowReportProvider.php',
            'SafetyManagement/Reporting/IncidentActions/Providers/SafetyIncidentActionsReportProvider.php',
            'SafetyManagement/Reporting/Admission/Providers/WorkforceAdmissionReportProvider.php',
        ] as $provider) {
            $source = (string) file_get_contents(
                $root.'/app/BusinessModules/Features/'.$provider,
            );
            self::assertStringContainsString('ReportSnapshotSealStore $seals', $source);
            self::assertStringNotContainsString('CanonicalReportSnapshotSealer $sealer', $source);
        }
    }

    public function test_r25_evidence_identity_is_sensitive_for_every_requirement_type(): void
    {
        foreach ([
            'Queries/WorkforceAdmissionRowQuery.php',
            'DrillDown/WorkforceAdmissionDrillDownProvider.php',
        ] as $relativePath) {
            $source = (string) file_get_contents(
                dirname(__DIR__, 3).'/app/BusinessModules/Features/SafetyManagement/Reporting/Admission/'.$relativePath,
            );

            self::assertStringContainsString(
                'if ($context->visibility->canViewSensitive)',
                $source,
            );
            self::assertStringNotContainsString('if (! $medical)', $source);
        }
    }
}
