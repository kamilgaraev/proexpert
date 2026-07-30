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
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers\WorkforceAdmissionReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceAdmissionContractTest extends TestCase
{
    #[Test]
    public function owner_ports_remain_split_and_sensitive_visibility_is_rechecked(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(WorkforceAdmissionReportProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(WorkforceAdmissionRowQuery::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(WorkforceAdmissionDrillDownProvider::class));
    }

    #[Test]
    public function medical_status_and_evidence_are_redacted_without_sensitive_visibility(): void
    {
        $row = (new SafetyAdmissionRow)->setRawAttributes([
            'row_key' => 'assignment:4:employee:5:requirement:medical',
            'snapshot_date' => '2026-07-26',
            'project_id' => 2,
            'safety_site_id' => 3,
            'workforce_assignment_id' => 4,
            'employee_id' => 5,
            'requirement_code' => 'medical',
            'requirement_type' => 'medical_exam',
            'evidence_type' => 'medical_exam',
            'status' => 'restricted',
            'blocked' => false,
            'verified' => true,
            'valid_until' => '2026-12-31',
            'evidence_id' => 12,
            'medical_details' => '{"source_type":"medical_exam","source_id":12}',
        ], true);
        $method = new \ReflectionMethod(WorkforceAdmissionRowQuery::class, 'serialize');
        $query = new WorkforceAdmissionRowQuery;

        $redacted = $method->invoke($query, $row, $this->context(false));
        $visible = $method->invoke($query, $row, $this->context(true));

        self::assertSame('valid', $redacted['status']);
        self::assertArrayNotHasKey('evidence_id', $redacted);
        self::assertArrayNotHasKey('medical_details', $redacted);
        self::assertSame('restricted', $visible['status']);
        self::assertSame(12, $visible['evidence_id']);
        self::assertSame(['source_type' => 'medical_exam', 'source_id' => 12], $visible['medical_details']);
    }

    #[Test]
    public function exact_medical_source_scope_is_enforced_even_with_sensitive_visibility(): void
    {
        $row = (new SafetyAdmissionRow)->setRawAttributes([
            'row_key' => 'assignment:4:employee:5:requirement:medical',
            'snapshot_date' => '2026-07-26',
            'project_id' => 2,
            'safety_site_id' => 3,
            'workforce_assignment_id' => 4,
            'employee_id' => 5,
            'requirement_code' => 'medical',
            'requirement_type' => 'medical_exam',
            'evidence_type' => 'medical_exam',
            'status' => 'valid',
            'blocked' => false,
            'verified' => true,
            'evidence_id' => 12,
        ], true);
        $method = new \ReflectionMethod(WorkforceAdmissionRowQuery::class, 'serialize');

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        $method->invoke(
            new WorkforceAdmissionRowQuery,
            $row,
            $this->context(true, [new ReportScopedResource('medical_exam', 99, 2)]),
        );
    }

    #[Test]
    public function complete_admission_sources_produce_complete_quality_without_foreign_metrics(): void
    {
        $snapshot = (new SafetyAdmissionSnapshot)->setRawAttributes([
            'eligible_count' => 4,
            'projected_count' => 4,
            'gap_count' => 0,
            'unknown_count' => 0,
        ], true);
        $provider = (new \ReflectionClass(WorkforceAdmissionReportProvider::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(WorkforceAdmissionReportProvider::class, 'quality');

        $quality = $method->invoke($provider, $snapshot);

        self::assertSame(ReportQualityStatus::COMPLETE, $quality->status);
        self::assertSame([], $quality->unknownMetrics);
    }

    private function context(bool $canViewSensitive, array $resources = []): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope(1, [1], [2], [], $timezone);

        return new ReportExecutionContext(
            new ReportActor(1, 'active', []),
            $scope,
            new ReportVisibility(true, true, true, true, false, $canViewSensitive, false),
            new AuthorizationDecisionContext('http', 1, [1], [2], $resources, $timezone, 'admission-redaction-test', null),
        );
    }
}
