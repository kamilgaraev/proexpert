<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use App\Enums\Contract\ContractAllocationTypeEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

final readonly class HoldingAllocationContextSnapshot
{
    public function __construct(
        public int $eventId,
        public int $allocationId,
        public int $contractId,
        public int $organizationId,
        public int $projectId,
        public string $allocationType,
        public ?string $allocatedAmount,
        public ?string $allocatedPercentage,
        public string $evidenceHash,
        public string $coverageStartedAt,
    ) {
        $amount = null;
        if ($allocatedAmount !== null) {
            try {
                $amount = BigDecimal::of($allocatedAmount);
            } catch (MathException) {
                throw new InvalidArgumentException('holding_allocation_context_snapshot_invalid');
            }
        }
        $percentage = null;
        if ($allocatedPercentage !== null) {
            try {
                $percentage = BigDecimal::of($allocatedPercentage);
            } catch (MathException) {
                throw new InvalidArgumentException('holding_allocation_context_snapshot_invalid');
            }
        }
        $type = ContractAllocationTypeEnum::tryFrom($allocationType);

        if (min($eventId, $allocationId, $contractId, $organizationId, $projectId) < 1
            || $type === null
            || ($amount !== null && $amount->isNegative())
            || ($percentage !== null && ($percentage->isNegative() || $percentage->isGreaterThan(100)))
            || ($type === ContractAllocationTypeEnum::FIXED && $amount === null)
            || ($type !== ContractAllocationTypeEnum::FIXED && $percentage === null)
            || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
            || trim($coverageStartedAt) === '') {
            throw new InvalidArgumentException('holding_allocation_context_snapshot_invalid');
        }
    }
}
