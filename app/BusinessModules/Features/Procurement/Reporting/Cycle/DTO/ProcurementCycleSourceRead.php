<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ProcurementCycleSourceRead
{
    public function __construct(
        public array $policiesById,
        public int $lineCount,
        public int $eventCount,
        public int $unscopedQuarantineLineCount,
        public int $unscopedQuarantineMaxEventId,
        public ?string $unscopedQuarantineMaxOccurredAt,
        public int $maxEventId,
        public ?string $maxOccurredAt,
    ) {
        if ($lineCount < 0 || $eventCount < 0 || $unscopedQuarantineLineCount < 0
            || $unscopedQuarantineMaxEventId < 0 || $maxEventId < 0) {
            throw new InvalidArgumentException('procurement_cycle_source_read_invalid');
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
            'event_count' => $this->eventCount,
            'line_count' => $this->lineCount,
            'max_event_id' => $this->maxEventId,
            'max_occurred_at' => $this->maxOccurredAt,
            'unscoped_quarantine_line_count' => $this->unscopedQuarantineLineCount,
            'unscoped_quarantine_max_event_id' => $this->unscopedQuarantineMaxEventId,
            'unscoped_quarantine_max_occurred_at' => $this->unscopedQuarantineMaxOccurredAt,
            'policies' => $policies,
            'scope' => $scope,
            'source_schema_version' => '1.0.0',
        ]));
    }
}
