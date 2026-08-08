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
            'snapshot_seal_signature',
            'GetReportRowsAction::class',
            'GetReportDrillDownAction::class',
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

    public function test_snapshot_integrity_does_not_depend_on_secret_keys(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php',
        );
        self::assertStringNotContainsString('private_key', $provider);
        self::assertStringNotContainsString('active_seal', $provider);
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
        $hydrator = (string) file_get_contents(
            $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php',
        );
        self::assertStringContainsString('lockForUpdate()', $sealStore);
        self::assertStringContainsString("DB::table('report_snapshot_seals')->insert", $sealStore);
        self::assertStringContainsString('report_snapshot_seal_immutable', $migration);
        self::assertStringNotContainsString('updateOrInsert', $sealStore);
        self::assertStringContainsString('pg_advisory_xact_lock', $sealStore);
        $contentHashMigration = (string) file_get_contents(
            $root.'/database/migrations/2026_08_08_000003_allow_content_hash_report_snapshot_seals.php',
        );
        self::assertStringContainsString("algorithm = 'sha256'", $contentHashMigration);
        self::assertStringContainsString("key_id = 'content_hash_v1'", $contentHashMigration);
        self::assertStringContainsString("algorithm = 'ed25519-sha256'", $contentHashMigration);
        $backfill = (string) file_get_contents(
            $root.'/app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportSnapshotSealBackfill.php',
        );
        self::assertStringContainsString("'status' => 'failed'", $backfill);
        self::assertStringContainsString("'status' => 'ready'", $backfill);
        self::assertStringContainsString('failure_fingerprint', $backfill);
        $race = (string) file_get_contents(
            $root.'/tests/Integration/Reporting/Waves23/ReportingSealRacePostgresTest.php',
        );
        self::assertStringContainsString('test_two_processes_persist_exactly_one_identical_content_hash_seal', $race);
        self::assertGreaterThanOrEqual(2, substr_count($race, '$race->spawn('));
        self::assertStringContainsString('$race->waitForChildren(', $race);

        foreach ([
            'QualityControl/Reporting/DefectFlow/Providers/QualityDefectFlowReportProvider.php',
            'SafetyManagement/Reporting/IncidentActions/Providers/SafetyIncidentActionsReportProvider.php',
            'SafetyManagement/Reporting/Admission/Providers/WorkforceAdmissionReportProvider.php',
        ] as $provider) {
            $source = (string) file_get_contents(
                $root.'/app/BusinessModules/Features/'.$provider,
            );
            self::assertStringContainsString('ReportSnapshotSealStore $seals', $source);
            self::assertStringNotContainsString('private_key', $source);
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
