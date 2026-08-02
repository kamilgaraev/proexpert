<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportingWaveHttpCursorParityTest extends TestCase
{
    #[DataProvider('reportCodes')]
    public function test_run_rows_drill_down_and_export_preserve_http_window_contract(
        string $reportCode,
        string $rowQuery,
        string $drillDownProvider,
    ): void
    {
        $result = (new HermeticReportingHttpHarness($reportCode))->runWaveCursorParity($reportCode);

        self::assertSame($reportCode, $result['report_code']);
        self::assertSame([201, 200, 200, 201], array_values($result['statuses']));
        self::assertSame(
            [$reportCode, '2026-01-01T00:00:00+00:00', [], 'ru-RU'],
            $result['run_contract'],
        );
        self::assertSame('rows-in-'.$reportCode, $result['rows_cursor_in']);
        self::assertSame('rows-next-'.$reportCode, $result['rows_cursor_out']);
        self::assertSame('drill-in-'.$reportCode, $result['drill_cursor_in']);
        self::assertSame('drill-next-'.$reportCode, $result['drill_cursor_out']);
        self::assertSame(['name', 'asc'], $result['export_sort']);
        self::assertSame(1, $result['action_calls']['createRun']);
        self::assertSame(1, $result['action_calls']['rows']);
        self::assertSame(1, $result['action_calls']['drillDown']);
        self::assertSame(1, $result['action_calls']['createExport']);
        self::assertTrue(is_a(
            $rowQuery,
            \App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery::class,
            true,
        ));
        self::assertTrue(is_a(
            $drillDownProvider,
            \App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider::class,
            true,
        ));
    }

    public static function reportCodes(): iterable
    {
        yield 'R23 quality defects' => [
            'quality_defect_flow',
            \App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery::class,
            \App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider::class,
        ];
        yield 'R24 incident actions' => [
            'safety_incident_actions',
            \App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery::class,
            \App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider::class,
        ];
        yield 'R25 workforce admission' => [
            'workforce_admission',
            \App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery::class,
            \App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider::class,
        ];
    }
}
