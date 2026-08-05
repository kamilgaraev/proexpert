<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class HoldingPerformanceProjectionCoverage
{
    public function __construct(
        public array $eligibleActVersionIds,
        public array $projectedActVersionIds,
        public array $contributingActVersionIds,
        public array $eligiblePaymentVersionIds,
        public array $projectedPaymentVersionIds,
        public array $contributingPaymentVersionIds,
        public string $watermark,
    ) {
        foreach ([
            $eligibleActVersionIds,
            $projectedActVersionIds,
            $contributingActVersionIds,
            $eligiblePaymentVersionIds,
            $projectedPaymentVersionIds,
            $contributingPaymentVersionIds,
        ] as $ids) {
            if (! array_is_list($ids)
                || $ids !== array_values(array_unique($ids))
                || array_filter($ids, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
                throw new InvalidArgumentException('holding_performance_projection_coverage_invalid');
            }
        }
        if (array_diff($projectedActVersionIds, $eligibleActVersionIds) !== []
            || array_diff($projectedPaymentVersionIds, $eligiblePaymentVersionIds) !== []
            || array_diff($contributingActVersionIds, $projectedActVersionIds) !== []
            || array_diff($contributingPaymentVersionIds, $projectedPaymentVersionIds) !== []
            || preg_match('/^[a-f0-9]{64}$/D', $watermark) !== 1) {
            throw new InvalidArgumentException('holding_performance_projection_coverage_invalid');
        }
    }

    public function gapCount(): int
    {
        return count($this->eligibleActVersionIds) - count($this->projectedActVersionIds)
            + count($this->eligiblePaymentVersionIds) - count($this->projectedPaymentVersionIds);
    }

    public function complete(): bool
    {
        return $this->gapCount() === 0;
    }
}
