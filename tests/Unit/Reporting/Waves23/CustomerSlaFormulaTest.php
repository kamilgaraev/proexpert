<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\DTO\CustomerWorkflowFact;
use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaClock;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaFormula;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomerSlaFormulaTest extends TestCase
{
    #[Test]
    public function first_response_requires_delivery_team_actor_side_at_event_time(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'issue',
                workflowId: 17,
                openedAt: CarbonImmutable::parse('2026-07-27T10:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T11:00:00+03:00'),
                events: [
                    [
                        'type' => CustomerWorkflowEventType::COMMENTED,
                        'actor_side' => CustomerActorSide::CUSTOMER,
                        'occurred_at' => CarbonImmutable::parse('2026-07-27T10:10:00+03:00'),
                    ],
                    [
                        'type' => CustomerWorkflowEventType::COMMENTED,
                        'actor_side' => CustomerActorSide::DELIVERY_TEAM,
                        'occurred_at' => CarbonImmutable::parse('2026-07-27T10:30:00+03:00'),
                    ],
                ],
                pauseWindows: [],
            ),
            $this->policy(),
        );

        self::assertSame(1_800, $metric->firstResponseSeconds);
        self::assertFalse($metric->firstResponseBreached);
    }

    #[Test]
    public function requester_reply_never_counts_as_response_and_open_age_is_right_censored(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'request',
                workflowId: 18,
                openedAt: CarbonImmutable::parse('2026-07-27T10:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T12:00:00+03:00'),
                events: [[
                    'type' => CustomerWorkflowEventType::COMMENTED,
                    'actor_side' => CustomerActorSide::CUSTOMER,
                    'occurred_at' => CarbonImmutable::parse('2026-07-27T10:30:00+03:00'),
                ]],
                pauseWindows: [],
            ),
            $this->policy(),
        );

        self::assertNull($metric->firstResponseSeconds);
        self::assertNull($metric->resolutionSeconds);
        self::assertSame(7_200, $metric->openAgingSeconds);
        self::assertTrue($metric->firstResponseBreached);
        self::assertFalse($metric->resolutionBreached);
    }

    #[Test]
    public function reopened_workflow_is_open_until_a_later_terminal_result(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'issue',
                workflowId: 19,
                openedAt: CarbonImmutable::parse('2026-07-27T09:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T13:00:00+03:00'),
                events: [
                    [
                        'type' => CustomerWorkflowEventType::RESOLVED,
                        'actor_side' => CustomerActorSide::DELIVERY_TEAM,
                        'occurred_at' => CarbonImmutable::parse('2026-07-27T10:00:00+03:00'),
                    ],
                    [
                        'type' => CustomerWorkflowEventType::REOPENED,
                        'actor_side' => CustomerActorSide::CUSTOMER,
                        'occurred_at' => CarbonImmutable::parse('2026-07-27T11:00:00+03:00'),
                    ],
                ],
                pauseWindows: [],
            ),
            $this->policy(),
        );

        self::assertSame(3_600, $metric->firstResponseSeconds);
        self::assertNull($metric->resolutionSeconds);
        self::assertSame(14_400, $metric->openAgingSeconds);
        self::assertFalse($metric->resolutionBreached);
    }

    #[Test]
    public function delivery_team_opening_is_not_a_customer_sla_observation(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'issue',
                workflowId: 20,
                openedAt: CarbonImmutable::parse('2026-07-27T09:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T13:00:00+03:00'),
                events: [],
                pauseWindows: [],
                openedActorSide: CustomerActorSide::DELIVERY_TEAM,
            ),
            $this->policy(),
        );

        self::assertNull($metric->openAgingSeconds);
        self::assertFalse($metric->actorSideComplete);
    }

    #[Test]
    public function terminal_workflow_without_delivery_response_keeps_first_response_breach(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'request',
                workflowId: 21,
                openedAt: CarbonImmutable::parse('2026-07-27T09:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T13:00:00+03:00'),
                events: [[
                    'type' => CustomerWorkflowEventType::RESOLVED,
                    'actor_side' => CustomerActorSide::CUSTOMER,
                    'occurred_at' => CarbonImmutable::parse('2026-07-27T11:00:00+03:00'),
                ]],
                pauseWindows: [],
            ),
            $this->policy(),
        );

        self::assertNull($metric->firstResponseSeconds);
        self::assertSame(7_200, $metric->resolutionSeconds);
        self::assertTrue($metric->firstResponseBreached);
    }

    private function policy(): CustomerSlaPolicy
    {
        return new CustomerSlaPolicy(
            timezone: 'Europe/Moscow',
            weekdayIntervals: [
                1 => [['opens' => '09:00', 'closes' => '18:00']],
                2 => [['opens' => '09:00', 'closes' => '18:00']],
                3 => [['opens' => '09:00', 'closes' => '18:00']],
                4 => [['opens' => '09:00', 'closes' => '18:00']],
                5 => [['opens' => '09:00', 'closes' => '18:00']],
            ],
            holidays: [],
            pauseStatuses: ['waiting_customer'],
            firstResponseTargetSeconds: 3_600,
            resolutionTargetSeconds: 28_800,
            version: 'customer-sla.v1',
        );
    }
}
