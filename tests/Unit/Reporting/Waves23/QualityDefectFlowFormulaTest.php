<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\DefectTransitionTimeline;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowPolicyVersion;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowFormula;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowFormulaTest extends TestCase
{
    #[Test]
    public function backlog_roll_forward_reconciles_from_golden_fixture(): void
    {
        $fixture = $this->fixture('happy');
        $input = $fixture['input'];

        self::assertSame(
            $fixture['expected']['closing'],
            (new QualityDefectFlowFormula)->rollForward($input['opening'], $input['created'], $input['reopened'], $input['closed']),
        );
    }

    #[Test]
    public function reopen_requires_terminal_to_non_terminal_transition_and_mature_cohort_excludes_it(): void
    {
        $fixture = $this->fixture('boundary');
        $input = $fixture['input'];
        $timeline = new DefectTransitionTimeline(
            1,
            $input['from_status'],
            $input['to_status'],
            new DateTimeImmutable($input['occurred_at']),
            new DateTimeImmutable($input['cohort_at']),
            new DateTimeImmutable($input['resolved_at']),
            $input['closure_evidence_present'],
        );
        $policy = (new QualityDefectFlowPolicyVersion)->forceFill([
            'terminal_statuses' => ['resolved', 'verified'],
            'maturity_days' => 30,
            'closure_evidence_required' => true,
        ]);
        $formula = new QualityDefectFlowFormula;
        $coverage = $formula->matureCohort([$timeline], $policy, new DateTimeImmutable('2026-07-26T00:00:00+03:00'));

        self::assertSame($fixture['expected']['reopened'], $formula->isReopen($timeline));
        self::assertSame($fixture['expected']['mature_numerator'], $coverage->numerator);
        self::assertSame($fixture['expected']['mature_denominator'], $coverage->denominator);
        self::assertSame($fixture['expected']['mature_ratio'], $coverage->ratio);
    }

    #[Test]
    public function mature_cohort_uses_policy_terminal_statuses_and_optional_evidence_rule(): void
    {
        $timeline = new DefectTransitionTimeline(
            7,
            'in_progress',
            'accepted',
            new DateTimeImmutable('2026-06-10T00:00:00+03:00'),
            new DateTimeImmutable('2026-06-01T00:00:00+03:00'),
            new DateTimeImmutable('2026-06-10T00:00:00+03:00'),
            false,
        );
        $policy = (new QualityDefectFlowPolicyVersion)->forceFill([
            'terminal_statuses' => ['accepted'],
            'maturity_days' => 30,
            'closure_evidence_required' => false,
        ]);

        $coverage = (new QualityDefectFlowFormula)->matureCohort(
            [$timeline],
            $policy,
            new DateTimeImmutable('2026-07-26T00:00:00+03:00'),
        );
        $reopen = new DefectTransitionTimeline(
            8,
            'accepted',
            'in_progress',
            new DateTimeImmutable('2026-07-12T00:00:00+03:00'),
            new DateTimeImmutable('2026-06-01T00:00:00+03:00'),
            null,
            false,
        );

        self::assertSame('1', $coverage->numerator);
        self::assertSame('1', $coverage->denominator);
        self::assertSame('1.0000', $coverage->ratio);
        self::assertTrue((new QualityDefectFlowFormula)->isReopen($reopen, $policy));
    }

    private function fixture(string $case): array
    {
        $json = file_get_contents(__DIR__."/../../../Fixtures/Reporting/waves-2-3/R23/{$case}.json");

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }
}
