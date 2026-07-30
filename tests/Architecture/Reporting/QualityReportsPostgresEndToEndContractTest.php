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
            'TrustedReportSnapshotSealVerifier',
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
}
