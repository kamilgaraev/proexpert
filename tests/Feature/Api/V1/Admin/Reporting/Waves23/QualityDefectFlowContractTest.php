<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowContractTest extends TestCase
{
    #[Test]
    public function owner_ports_remain_split_and_export_uses_row_query(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(QualityDefectFlowReportProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(QualityDefectFlowRowQuery::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(QualityDefectFlowDrillDownProvider::class));
        self::assertNotContains(ReportRowQuery::class, class_implements(QualityDefectFlowReportProvider::class));
    }

    #[Test]
    public function evidence_references_are_redacted_without_audit_visibility(): void
    {
        $row = (new QualityDefectFlowRow)->setRawAttributes([
            'row_key' => 'defect:9:event:2',
            'cohort_date' => '2026-06-01',
            'project_id' => 2,
            'contractor_id' => 3,
            'quality_defect_id' => 9,
            'event_version' => 2,
            'severity' => 'major',
            'status' => 'verified',
            'created_flag' => false,
            'reopened_flag' => false,
            'closed_flag' => true,
            'closing_flag' => false,
            'cycle_days' => 4,
            'evidence_refs' => '[{"id":14,"type":"closure"}]',
        ], true);
        $method = new \ReflectionMethod(QualityDefectFlowRowQuery::class, 'serialize');
        $query = new QualityDefectFlowRowQuery;

        $redacted = $method->invoke($query, $row, $this->context(false));
        $visible = $method->invoke($query, $row, $this->context(true));

        self::assertSame([], $redacted['evidence_refs']);
        self::assertSame([['id' => 14, 'type' => 'closure']], $visible['evidence_refs']);
    }

    private function context(bool $canViewAudit): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope(1, [1], [2], [], $timezone);

        return new ReportExecutionContext(
            new ReportActor(1, 'active', []),
            $scope,
            new ReportVisibility(true, true, true, true, false, false, $canViewAudit),
            new AuthorizationDecisionContext('http', 1, [1], [2], [], $timezone, 'quality-redaction-test', null),
        );
    }
}
