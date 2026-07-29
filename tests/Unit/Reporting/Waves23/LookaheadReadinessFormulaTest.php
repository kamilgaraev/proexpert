<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessFormula;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LookaheadReadinessFormulaTest extends TestCase
{
    public function test_readiness_uses_every_eligible_task_in_denominator(): void
    {
        $formula = new LookaheadReadinessFormula;
        $policy = $this->policy();
        $summary = $formula->summarize([
            $formula->evaluate($this->input(1, []), $policy),
            $formula->evaluate($this->input(2, [
                new LookaheadConstraintState(9, 'permit', 'high', 'open', null, null),
            ]), $policy),
        ]);

        self::assertSame('1', $summary->numerator);
        self::assertSame('2', $summary->denominator);
        self::assertSame('0.50000000', $summary->ratio);
    }

    public function test_expired_waiver_does_not_release_hard_constraint(): void
    {
        $metric = (new LookaheadReadinessFormula)->evaluate($this->input(1, [
            new LookaheadConstraintState(
                9,
                'permit',
                'high',
                'waived',
                new DateTimeImmutable('2026-07-28'),
                'evidence-9',
            ),
        ]), $this->policy());

        self::assertFalse($metric->ready);
        self::assertSame('LOOKAHEAD_WAIVER_EXPIRED', $metric->warningCode);
    }

    public function test_resolved_rfi_without_pinned_linked_evidence_is_partial(): void
    {
        $policy = new LookaheadReadinessPolicyVersion(
            1,
            10,
            30,
            ['planned'],
            ['rfi_missing'],
            ['high'],
            true,
            new DateTimeImmutable('2026-07-01'),
            null,
            'Europe/Moscow',
            str_repeat('c', 64),
        );
        $metric = (new LookaheadReadinessFormula())->evaluate($this->input(1, [
            new LookaheadConstraintState(10, 'rfi_missing', 'high', 'resolved', null, null),
        ]), $policy);

        self::assertTrue($metric->ready);
        self::assertSame('LOOKAHEAD_LINKED_EVIDENCE_MISSING', $metric->warningCode);
    }

    private function input(int $taskId, array $constraints): LookaheadEligibilityInput
    {
        return new LookaheadEligibilityInput(
            $taskId,
            false,
            'planned',
            new DateTimeImmutable('2026-08-05'),
            new DateTimeImmutable('2026-07-29'),
            $constraints,
        );
    }

    private function policy(): LookaheadReadinessPolicyVersion
    {
        return new LookaheadReadinessPolicyVersion(
            1,
            10,
            30,
            ['planned', 'in_progress'],
            ['permit'],
            ['high', 'critical'],
            true,
            new DateTimeImmutable('2026-07-01'),
            null,
            'Europe/Moscow',
            str_repeat('b', 64),
        );
    }
}
