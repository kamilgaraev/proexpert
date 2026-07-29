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

    private function fixture(string $case): array
    {
        $json = file_get_contents(__DIR__."/../../../Fixtures/Reporting/waves-2-3/R25/{$case}.json");

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
