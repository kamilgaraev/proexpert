<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\EffectiveAssignmentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\EffectiveAssignmentFact;
use App\BusinessModules\Features\WorkforceManagement\Reporting\EffectiveAssignmentResolver;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceReportQueryService;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceOperationsSourceTest extends TestCase
{
    #[Test]
    public function workforce_capacity_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(WorkforceCapacityProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(WorkforceReportQueryService::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(WorkforceReportQueryService::class));
    }

    #[Test]
    public function attendance_execution_provider_contract(): void
    {
        self::assertContains(ReportDataProvider::class, class_implements(AttendanceExecutionProvider::class));
        self::assertContains(ReportRowQuery::class, class_implements(WorkforceReportQueryService::class));
        self::assertContains(ReportDrillDownProvider::class, class_implements(WorkforceReportQueryService::class));
    }

    #[Test]
    public function overlapping_exact_assignments_do_not_inflate_fte(): void
    {
        $duplicate = $this->assignment(101, 7, '2026-07-01', '2026-08-01', '0.60');
        $resolver = new EffectiveAssignmentResolver($this->assignmentSource([$duplicate, $duplicate]));

        $resolution = $resolver->forDate(10, 7, new DateTimeImmutable('2026-07-20'));
        $metrics = (new WorkforceCapacityFormula())->calculate(
            approvedFte: '1.00',
            assignments: $resolution->assignments,
            plannedCapacityHours: '168.00',
            rateType: 'hourly',
            rate: '25.00',
            currency: 'USD',
        );

        self::assertSame('0.60', $metrics->assignedFte);
        self::assertSame('0.40', $metrics->vacancyFte);
        self::assertSame('0.00', $metrics->overstaffingFte);
        self::assertSame('100.80', $metrics->assignedCapacityHours);
        self::assertSame('2520.00', $metrics->periodCostRunRate);
    }

    #[Test]
    public function planned_fte_is_conserved_across_project_rows(): void
    {
        $formula = new WorkforceCapacityFormula();

        $underfilled = $formula->allocatePlannedFte('1.00', [
            'project:20' => '0.40',
            'project:21' => '0.30',
        ]);
        $overstaffed = $formula->allocatePlannedFte('0.50', [
            'project:20' => '0.40',
            'project:21' => '0.40',
        ]);

        self::assertSame([
            'none' => '0.30',
            'project:20' => '0.40',
            'project:21' => '0.30',
        ], $underfilled);
        self::assertSame([
            'project:20' => '0.25',
            'project:21' => '0.25',
        ], $overstaffed);
    }

    #[Test]
    public function adjacent_assignments_use_half_open_shared_boundary(): void
    {
        $resolver = new EffectiveAssignmentResolver($this->assignmentSource([
            $this->assignment(101, 7, '2026-07-01', '2026-07-15', '0.60'),
            $this->assignment(102, 7, '2026-07-15', '2026-08-01', '0.80'),
        ]));

        $resolution = $resolver->forDate(10, 7, new DateTimeImmutable('2026-07-15'));
        $metrics = (new WorkforceCapacityFormula())->calculate(
            approvedFte: '2.00',
            assignments: $resolution->assignments,
            plannedCapacityHours: '336.00',
            rateType: 'hourly',
            rate: null,
            currency: null,
        );

        self::assertSame([102], array_map(
            static fn (EffectiveAssignmentFact $fact): int => $fact->assignmentId,
            $resolution->assignments,
        ));
        self::assertSame('0.80', $metrics->assignedFte);
        self::assertSame('1.20', $metrics->vacancyFte);
        self::assertNull($metrics->periodCostRunRate);
        self::assertContains('MISSING_RATE_CURRENCY', $metrics->qualityWarnings);
    }

    #[Test]
    public function incompatible_active_overlaps_fail_closed(): void
    {
        $resolver = new EffectiveAssignmentResolver($this->assignmentSource([
            $this->assignment(101, 7, '2026-07-01', '2026-08-01', '0.60', projectId: 20),
            $this->assignment(102, 7, '2026-07-15', '2026-08-15', '0.60', projectId: 21),
        ]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('WORKFORCE_ASSIGNMENT_OVERLAP');

        $resolver->forDate(10, 7, new DateTimeImmutable('2026-07-20'));
    }

    #[Test]
    public function approved_absence_preserves_execution_and_zero_denominator_is_null(): void
    {
        $formula = new AttendanceExecutionFormula();

        $covered = $formula->calculate(
            eligibleHours: '8.00',
            presentHours: '0.00',
            approvedAbsenceHours: '8.00',
            overtimeHours: '0.00',
            lateHours: '0.00',
            earlyHours: '0.00',
            corrected: true,
        );
        $empty = $formula->calculate(
            eligibleHours: '0.00',
            presentHours: '0.00',
            approvedAbsenceHours: '0.00',
            overtimeHours: '0.00',
            lateHours: '0.00',
            earlyHours: '0.00',
            corrected: false,
        );

        self::assertFalse($covered->violation);
        self::assertSame('0.00', $covered->unexplainedAbsenceHours);
        self::assertSame('100.00', $covered->executionPercent);
        self::assertSame('100.00', $covered->correctionRate);
        self::assertNull($empty->executionPercent);
        self::assertSame('0.00', $empty->overtimeHours);
    }

    #[Test]
    public function attendance_conserves_eligible_hours_and_separates_overtime(): void
    {
        $metrics = (new AttendanceExecutionFormula())->calculate(
            eligibleHours: '8.00',
            presentHours: '10.00',
            approvedAbsenceHours: '3.00',
            overtimeHours: '0.00',
            lateHours: '0.00',
            earlyHours: '0.00',
            corrected: false,
        );

        self::assertSame('8.00', $metrics->presentHours);
        self::assertSame('0.00', $metrics->approvedAbsenceHours);
        self::assertSame('0.00', $metrics->unexplainedAbsenceHours);
        self::assertSame('2.00', $metrics->overtimeHours);
        self::assertSame('100.00', $metrics->executionPercent);
    }

    private function assignmentSource(array $assignments): EffectiveAssignmentSource
    {
        return new class($assignments) implements EffectiveAssignmentSource {
            public function __construct(private readonly array $assignments)
            {
            }

            public function forEmployee(int $organizationId, int $employeeId): array
            {
                return array_values(array_filter(
                    $this->assignments,
                    static fn (EffectiveAssignmentFact $fact): bool => $fact->organizationId === $organizationId
                        && $fact->employeeId === $employeeId,
                ));
            }
        };
    }

    private function assignment(
        int $assignmentId,
        int $employeeId,
        string $validFrom,
        ?string $validTo,
        string $fte,
        int $projectId = 20,
    ): EffectiveAssignmentFact {
        return new EffectiveAssignmentFact(
            assignmentId: $assignmentId,
            organizationId: 10,
            employeeId: $employeeId,
            staffUnitId: 30,
            departmentId: 40,
            positionId: 50,
            projectId: $projectId,
            workScheduleId: 60,
            validFrom: new DateTimeImmutable($validFrom),
            validToExclusive: $validTo === null ? null : new DateTimeImmutable($validTo),
            fte: $fte,
            sourceVersion: 3,
        );
    }
}
