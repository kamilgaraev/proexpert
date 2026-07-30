<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\DTO\CustomerWorkflowFact;
use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaClock;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaFormula;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomerSlaContractTest extends TestCase
{
    #[Test]
    public function unknown_actor_side_produces_no_sla_payload(): void
    {
        $metric = (new CustomerSlaFormula(new CustomerSlaClock))->evaluate(
            new CustomerWorkflowFact(
                workflowType: 'issue',
                workflowId: 77,
                openedAt: CarbonImmutable::parse('2026-07-27T09:00:00+03:00'),
                asOf: CarbonImmutable::parse('2026-07-27T13:00:00+03:00'),
                events: [[
                    'type' => CustomerWorkflowEventType::COMMENTED,
                    'actor_side' => CustomerActorSide::UNKNOWN,
                    'occurred_at' => CarbonImmutable::parse('2026-07-27T10:00:00+03:00'),
                ]],
                pauseWindows: [],
            ),
            new CustomerSlaPolicy(
                timezone: 'Europe/Moscow',
                weekdayIntervals: [1 => [['opens' => '09:00', 'closes' => '18:00']]],
                holidays: [],
                pauseStatuses: [],
                firstResponseTargetSeconds: 3600,
                resolutionTargetSeconds: 7200,
                version: 'customer-sla.v1',
            ),
        );

        self::assertFalse($metric->actorSideComplete);
        self::assertNull($metric->firstResponseSeconds);
        self::assertNull($metric->resolutionSeconds);
        self::assertNull($metric->openAgingSeconds);
        self::assertNull($metric->firstResponseBreached);
        self::assertNull($metric->resolutionBreached);
    }
}
