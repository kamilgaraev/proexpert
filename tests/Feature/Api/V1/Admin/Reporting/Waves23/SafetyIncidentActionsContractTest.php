<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyIncidentActionsContractTest extends TestCase
{
    #[Test]
    public function owner_ports_remain_split_and_medical_data_is_outside_incident_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(SafetyIncidentActionsReportProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(SafetyIncidentRowQuery::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(SafetyIncidentDrillDownProvider::class));
    }

    #[Test]
    public function evidence_is_redacted_without_audit_visibility_and_medical_data_is_absent(): void
    {
        $row = (new SafetyIncidentRow)->setRawAttributes([
            'row_key' => 'corrective_action:9:event:2',
            'event_date' => '2026-07-12',
            'project_id' => 2,
            'safety_site_id' => 3,
            'subject_type' => 'corrective_action',
            'subject_id' => 9,
            'event_version' => 2,
            'category' => 'incident',
            'severity' => 'high',
            'status' => 'verified',
            'owner_user_id' => 4,
            'due_date' => '2026-07-15',
            'created_flag' => false,
            'reopened_flag' => false,
            'closed_flag' => true,
            'closure_verified' => true,
            'closure_days' => 3,
            'evidence_type' => 'incident_closure',
            'evidence_id' => 9,
        ], true);
        $method = new \ReflectionMethod(SafetyIncidentRowQuery::class, 'serialize');
        $query = new SafetyIncidentRowQuery;

        $redacted = $method->invoke($query, $row, $this->context(false));
        $visible = $method->invoke($query, $row, $this->context(true));

        self::assertNull($redacted['evidence_id']);
        self::assertSame(9, $visible['evidence_id']);
        self::assertArrayNotHasKey('medical_details', $redacted);
    }

    #[Test]
    public function audit_visibility_does_not_bypass_exact_incident_evidence_scope(): void
    {
        $row = (new SafetyIncidentRow)->setRawAttributes([
            'row_key' => 'incident:12:event:1',
            'event_date' => '2026-06-01',
            'project_id' => 2,
            'subject_type' => 'incident',
            'subject_id' => 12,
            'event_version' => 1,
            'evidence_type' => 'incident_closure',
            'evidence_id' => 12,
        ], true);
        $context = $this->context(true, [
            new ReportScopedResource('safety_incident', 12, 2),
            new ReportScopedResource('incident_closure', 13, 2),
        ]);

        $this->expectException(ReportContractException::class);
        (new \ReflectionMethod(SafetyIncidentRowQuery::class, 'serialize'))
            ->invoke(new SafetyIncidentRowQuery, $row, $context);
    }

    private function context(bool $canViewAudit, array $resources = []): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope(1, [1], [2], [], $timezone);

        return new ReportExecutionContext(
            new ReportActor(1, 'active', []),
            $scope,
            new ReportVisibility(true, true, true, true, false, false, $canViewAudit),
            new AuthorizationDecisionContext('http', 1, [1], [2], $resources, $timezone, 'safety-redaction-test', null),
        );
    }
}
