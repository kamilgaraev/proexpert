<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaMetric;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\DTO\CustomerWorkflowFact;
use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;

final readonly class CustomerSlaFormula
{
    public function __construct(private CustomerSlaClock $clock) {}

    public function evaluate(
        CustomerWorkflowFact $fact,
        CustomerSlaPolicy $policy,
    ): CustomerSlaMetric {
        if ($fact->openedActorSide !== CustomerActorSide::CUSTOMER) {
            return new CustomerSlaMetric(null, null, null, null, null, false);
        }

        $events = $fact->events;
        usort(
            $events,
            static fn (array $left, array $right): int => $left['occurred_at'] <=> $right['occurred_at']
                ?: ($left['source_version'] ?? 0) <=> ($right['source_version'] ?? 0)
                ?: strcmp($left['type']->value, $right['type']->value),
        );

        $firstResponseAt = null;
        $resolvedAt = null;
        $actorSideComplete = true;
        foreach ($events as $event) {
            if ($event['actor_side'] === CustomerActorSide::UNKNOWN) {
                $actorSideComplete = false;
            }
            if (
                $firstResponseAt === null
                && $event['actor_side'] === CustomerActorSide::DELIVERY_TEAM
                && $event['type'] !== CustomerWorkflowEventType::OPENED
            ) {
                $firstResponseAt = $event['occurred_at'];
            }
            if ($event['type'] === CustomerWorkflowEventType::RESOLVED) {
                $resolvedAt = $event['occurred_at'];
            }
            if ($event['type'] === CustomerWorkflowEventType::REOPENED) {
                $resolvedAt = null;
            }
        }
        if (! $actorSideComplete) {
            return new CustomerSlaMetric(null, null, null, null, null, false);
        }

        $firstResponseSeconds = $firstResponseAt === null
            ? null
            : $this->clock->elapsedBusinessSeconds($fact->openedAt, $firstResponseAt, $policy, $fact->pauseWindows);
        $resolutionSeconds = $resolvedAt === null
            ? null
            : $this->clock->elapsedBusinessSeconds($fact->openedAt, $resolvedAt, $policy, $fact->pauseWindows);
        $openAgingSeconds = $resolvedAt === null
            ? $this->clock->elapsedBusinessSeconds($fact->openedAt, $fact->asOf, $policy, $fact->pauseWindows)
            : null;
        $unansweredAgingSeconds = $firstResponseAt === null
            ? $this->clock->elapsedBusinessSeconds(
                $fact->openedAt,
                $resolvedAt ?? $fact->asOf,
                $policy,
                $fact->pauseWindows,
            )
            : null;
        $firstResponseBreached = $firstResponseSeconds !== null
            ? $firstResponseSeconds > $policy->firstResponseTargetSeconds
            : $unansweredAgingSeconds !== null
                && $unansweredAgingSeconds > $policy->firstResponseTargetSeconds;
        $resolutionBreached = $resolutionSeconds !== null
            ? $resolutionSeconds > $policy->resolutionTargetSeconds
            : $openAgingSeconds !== null && $openAgingSeconds > $policy->resolutionTargetSeconds;

        return new CustomerSlaMetric(
            $firstResponseSeconds,
            $resolutionSeconds,
            $openAgingSeconds,
            $firstResponseBreached,
            $resolutionBreached,
            $actorSideComplete,
        );
    }
}
