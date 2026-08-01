<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ProcurementCycleSourceRead
{
    public function __construct(
        public array $eventsByLine,
        public array $policiesById,
        public int $maxEventId,
        public ?string $maxOccurredAt,
    ) {
        if ($maxEventId < 0 || ! array_is_list($eventsByLine)) {
            throw new InvalidArgumentException('procurement_cycle_source_read_invalid');
        }
        foreach ($eventsByLine as $events) {
            if (! is_array($events) || ! array_is_list($events) || $events === []) {
                throw new InvalidArgumentException('procurement_cycle_source_read_invalid');
            }
            foreach ($events as $event) {
                if (! $event instanceof ProcurementCycleEvent) {
                    throw new InvalidArgumentException('procurement_cycle_source_read_invalid');
                }
            }
        }
        foreach ($policiesById as $id => $policy) {
            if (! is_int($id) || ! $policy instanceof ProcurementCyclePolicySnapshot || $policy->versionId !== $id) {
                throw new InvalidArgumentException('procurement_cycle_source_read_invalid');
            }
        }
    }

    public function sourceVersion(array $scope, array $filters, string $asOf): string
    {
        $policies = [];
        foreach ($this->policiesById as $policy) {
            $policies[] = ['id' => $policy->versionId, 'hash' => $policy->canonicalHash];
        }
        usort($policies, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return hash('sha256', CanonicalJson::encode([
            'as_of' => $asOf,
            'filters' => $filters,
            'formula_version' => 'procurement-cycle.v1',
            'max_event_id' => $this->maxEventId,
            'max_occurred_at' => $this->maxOccurredAt,
            'policies' => $policies,
            'scope' => $scope,
            'source_schema_version' => '1.0.0',
        ]));
    }
}
