<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessFormula;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessPolicyDefinition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LookaheadProductionHardeningTest extends TestCase
{
    public function test_default_policy_has_a_canonical_hash_derived_from_every_business_field(): void
    {
        $definition = LookaheadReadinessPolicyDefinition::default(
            organizationId: 10,
            effectiveFrom: new DateTimeImmutable('2026-07-30T00:00:00+00:00'),
        );

        self::assertSame(30, $definition->horizonDays);
        self::assertSame(
            ['not_started', 'in_progress', 'waiting', 'on_hold'],
            $definition->eligibleTaskStatuses,
        );
        self::assertSame([
            'access_blocked',
            'customer_decision',
            'design_question',
            'executive_doc_missing',
            'labor_missing',
            'machinery_missing',
            'material_missing',
            'other',
            'procurement',
            'quality_blocker',
            'rfi',
            'safety_permit_missing',
            'weather_risk',
        ], $definition->mandatoryConstraintTypes);
        self::assertSame(['critical', 'hard'], $definition->hardSeverities);
        self::assertTrue($definition->waiverEvidenceRequired);
        self::assertSame(
            'b79e62c589d544942921a9a86d42a5c63bf12342c4f56cb234d063addabe44e8',
            $definition->sourceHash(),
        );

        self::assertNotSame(
            $definition->sourceHash(),
            (new LookaheadReadinessPolicyDefinition(
                organizationId: 10,
                projectId: null,
                horizonDays: 31,
                eligibleTaskStatuses: ['not_started', 'in_progress', 'waiting', 'on_hold'],
                mandatoryConstraintTypes: [
                    'access_blocked',
                    'customer_decision',
                    'design_question',
                    'executive_doc_missing',
                    'labor_missing',
                    'machinery_missing',
                    'material_missing',
                    'other',
                    'procurement',
                    'quality_blocker',
                    'rfi',
                    'safety_permit_missing',
                    'weather_risk',
                ],
                hardSeverities: ['critical', 'hard'],
                waiverEvidenceRequired: true,
                effectiveFrom: new DateTimeImmutable('2026-07-30T00:00:00+00:00'),
                effectiveUntil: null,
                timezone: 'UTC',
            ))->sourceHash(),
        );
    }

    public function test_closed_mandatory_rfi_without_linked_evidence_is_not_ready(): void
    {
        $metric = (new LookaheadReadinessFormula)->evaluate(
            $this->input([
                new LookaheadConstraintState(
                    constraintId: 701,
                    type: 'rfi',
                    severity: 'hard',
                    status: 'resolved',
                    waiverUntil: null,
                    waiverEvidenceRef: null,
                    openedAt: new DateTimeImmutable('2026-07-20T00:00:00+00:00'),
                ),
            ]),
            $this->policy(),
        );

        self::assertFalse($metric->ready);
        self::assertSame([701], $metric->blockingConstraintIds);
        self::assertSame(1, $metric->hardBlockers);
        self::assertSame(9, $metric->maxConstraintAgeDays);
        self::assertSame('LOOKAHEAD_LINKED_EVIDENCE_MISSING', $metric->warningCode);
    }

    public function test_eligibility_explanation_pins_the_historical_task_identity(): void
    {
        $explanation = $this->input([])->eligibilityExplanation();

        self::assertSame([
            'task_status' => 'planned',
            'task_type' => 'task',
            'task_state_version' => 7,
            'task_state_source_hash' => str_repeat('a', 64),
            'task_state_effective_at' => '2026-07-29T10:00:00+00:00',
        ], $explanation);
    }

    private function input(array $constraints): LookaheadEligibilityInput
    {
        return new LookaheadEligibilityInput(
            taskId: 101,
            container: false,
            status: 'planned',
            plannedStart: new DateTimeImmutable('2026-08-05T00:00:00+00:00'),
            asOf: new DateTimeImmutable('2026-07-29T00:00:00+00:00'),
            constraints: $constraints,
            projectId: 201,
            scheduleId: 301,
            taskType: 'task',
            taskStateVersion: 7,
            taskStateSourceHash: str_repeat('a', 64),
            taskStateEffectiveAt: new DateTimeImmutable('2026-07-29T10:00:00+00:00'),
        );
    }

    private function policy(): LookaheadReadinessPolicyVersion
    {
        return new LookaheadReadinessPolicyVersion(
            version: 1,
            organizationId: 10,
            horizonDays: 30,
            eligibleTaskStatuses: ['planned', 'in_progress'],
            mandatoryConstraintTypes: ['design', 'permit', 'procurement', 'rfi', 'site'],
            hardSeverities: ['critical', 'hard'],
            waiverEvidenceRequired: true,
            effectiveFrom: new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
            effectiveUntil: null,
            timezone: 'UTC',
            sourceHash: str_repeat('c', 64),
        );
    }
}
