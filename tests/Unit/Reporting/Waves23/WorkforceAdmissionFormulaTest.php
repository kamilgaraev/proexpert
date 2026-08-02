<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\SafetySiteAssignmentService;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\WorkforceAdmissionFormula;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceAdmissionFormulaTest extends TestCase
{
    #[Test]
    public function valid_mandatory_requirements_admit_person(): void
    {
        $fixture = $this->fixture('happy');
        $input = $fixture['input'];
        $metric = (new WorkforceAdmissionFormula)->evaluate(
            $input['assignment_id'],
            $input['person_id'],
            $input['site_id'],
            $input['date'],
            $input['requirements'],
        );

        self::assertSame($fixture['expected']['status'], $metric->status);
        self::assertSame($fixture['expected']['blocked'], $metric->blocked);
        self::assertSame($fixture['expected']['blocker_codes'], $metric->blockerCodes);
    }

    #[Test]
    public function missing_or_expired_mandatory_requirement_blocks_and_person_is_counted_once(): void
    {
        $fixture = $this->fixture('boundary');
        $input = $fixture['input'];
        $formula = new WorkforceAdmissionFormula;
        $metric = $formula->evaluate(
            $input['assignment_id'],
            $input['person_id'],
            $input['site_id'],
            $input['date'],
            $input['requirements'],
        );
        $summary = $formula->summarize([$metric, $metric]);

        self::assertSame($fixture['expected']['status'], $metric->status);
        self::assertSame($fixture['expected']['blocked'], $metric->blocked);
        self::assertSame($fixture['expected']['blocker_codes'], $metric->blockerCodes);
        self::assertSame($fixture['expected']['person_denominator'], $summary->personDenominator);
    }

    #[Test]
    public function malformed_requirement_expiry_is_rejected_instead_of_treated_as_unlimited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_admission_requirement_invalid');

        (new WorkforceAdmissionFormula)->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'medical',
            'type' => 'medical_exam',
            'status' => 'valid',
            'mandatory' => true,
            'verified' => true,
            'valid_until' => '2026-02-31',
            'evidence_type' => 'medical_exam',
            'evidence_id' => 5,
        ]]);
    }

    #[Test]
    public function malformed_site_assignment_interval_is_rejected_before_persistence(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('REPORT_SOURCE_UNAVAILABLE');

        (new SafetySiteAssignmentService)->assign(
            organizationId: 1,
            projectId: 2,
            siteId: 3,
            workforceAssignmentId: 4,
            employeeId: 5,
            validFrom: '2026-02-31',
            validTo: null,
            mappingSource: 'approved_site_mapping',
        );
    }

    #[Test]
    public function waiver_is_blocking_unless_policy_allows_it_and_required_evidence_exists(): void
    {
        $formula = new WorkforceAdmissionFormula;
        $blocked = $formula->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'training',
            'type' => 'training',
            'status' => 'waived',
            'mandatory' => true,
            'verified' => true,
            'valid_until' => null,
            'evidence_type' => 'employee_requirement',
            'evidence_id' => 7,
            'waiver_allowed' => false,
            'waiver_evidence_required' => true,
        ]]);
        $allowed = $formula->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'training',
            'type' => 'training',
            'status' => 'waived',
            'mandatory' => true,
            'verified' => true,
            'valid_until' => null,
            'evidence_type' => null,
            'evidence_id' => null,
            'waiver_allowed' => true,
            'waiver_evidence_required' => false,
        ]]);

        self::assertSame('not_admitted', $blocked->status);
        self::assertSame(['training'], $blocked->blockerCodes);
        self::assertSame('partial', $allowed->status);
        self::assertSame([], $allowed->blockerCodes);
    }

    #[Test]
    public function failed_employee_lifecycle_is_a_mandatory_blocker(): void
    {
        $metric = (new WorkforceAdmissionFormula)->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'employment_lifecycle:employee_dismissed',
            'type' => 'employment_lifecycle',
            'status' => 'failed',
            'mandatory' => true,
            'verified' => true,
            'valid_until' => null,
            'evidence_type' => 'workforce_employee',
            'evidence_id' => null,
        ]]);

        self::assertSame('not_admitted', $metric->status);
        self::assertSame(['employment_lifecycle:employee_dismissed'], $metric->blockerCodes);
    }

    #[Test]
    public function person_is_counted_once_across_multiple_sites_using_the_worst_status(): void
    {
        $formula = new WorkforceAdmissionFormula;
        $admitted = $formula->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'training',
            'status' => 'fulfilled',
            'mandatory' => true,
            'verified' => true,
        ]]);
        $blocked = $formula->evaluate(2, 20, 31, '2026-07-26', [[
            'code' => 'medical',
            'status' => 'missing',
            'mandatory' => true,
            'verified' => false,
        ]]);

        $summary = $formula->summarize([$admitted, $blocked]);

        self::assertSame(1, $summary->personDenominator);
        self::assertSame(0, $summary->admittedPeople);
        self::assertSame(1, $summary->notAdmittedPeople);
    }

    #[Test]
    public function missing_status_is_not_silently_replaced_by_a_fallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_admission_requirement_invalid');

        (new WorkforceAdmissionFormula)->evaluate(1, 20, 30, '2026-07-26', [[
            'code' => 'training',
            'type' => 'training',
            'mandatory' => true,
            'verified' => false,
        ]]);
    }

    private function fixture(string $case): array
    {
        $json = file_get_contents(__DIR__."/../../../Fixtures/Reporting/waves-2-3/R25/{$case}.json");

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
