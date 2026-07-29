<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\DTO;

use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CustomerWorkflowFact
{
    public function __construct(
        public string $workflowType,
        public int $workflowId,
        public CarbonImmutable $openedAt,
        public CarbonImmutable $asOf,
        public array $events,
        public array $pauseWindows,
        public CustomerActorSide $openedActorSide = CustomerActorSide::CUSTOMER,
    ) {
        if (
            ! in_array($workflowType, ['issue', 'request'], true)
            || $workflowId < 1
            || $asOf < $openedAt
            || ! array_is_list($events)
            || ! array_is_list($pauseWindows)
        ) {
            throw new InvalidArgumentException('customer_workflow_fact_invalid');
        }

        foreach ($events as $event) {
            $keys = is_array($event) ? array_keys($event) : [];
            if (
                ! is_array($event)
                || ! in_array($keys, [
                    ['type', 'actor_side', 'occurred_at'],
                    ['type', 'actor_side', 'occurred_at', 'source_version'],
                ], true)
                || ! $event['type'] instanceof CustomerWorkflowEventType
                || ! $event['actor_side'] instanceof CustomerActorSide
                || ! $event['occurred_at'] instanceof CarbonImmutable
                || (array_key_exists('source_version', $event)
                    && (! is_int($event['source_version']) || $event['source_version'] < 1))
                || $event['occurred_at'] < $openedAt
                || $event['occurred_at'] > $asOf
            ) {
                throw new InvalidArgumentException('customer_workflow_fact_invalid');
            }
        }

        foreach ($pauseWindows as $pauseWindow) {
            if (! $pauseWindow instanceof CustomerSlaPauseWindow) {
                throw new InvalidArgumentException('customer_workflow_fact_invalid');
            }
        }
    }
}
