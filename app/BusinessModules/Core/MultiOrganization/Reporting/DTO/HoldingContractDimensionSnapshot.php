<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

final readonly class HoldingContractDimensionSnapshot
{
    public function __construct(
        public int $eventId,
        public int $contractId,
        public int $organizationId,
        public ?int $contractorId,
        public ?int $counterpartyOrganizationId,
        public string $contractStatus,
        public ?string $workTypeCategory,
        public ?string $totalAmount,
        public string $currency,
        public string $evidenceHash,
        public string $coverageStartedAt,
    ) {
        $amount = null;
        if ($totalAmount !== null) {
            try {
                $amount = BigDecimal::of($totalAmount);
            } catch (MathException) {
                throw new InvalidArgumentException('holding_contract_dimension_snapshot_invalid');
            }
        }

        if (min($eventId, $contractId, $organizationId) < 1
            || ($contractorId !== null && $contractorId < 1)
            || ($counterpartyOrganizationId !== null && $counterpartyOrganizationId < 1)
            || ContractStatusEnum::tryFrom($contractStatus) === null
            || ($workTypeCategory !== null
                && ContractWorkTypeCategoryEnum::tryFrom($workTypeCategory) === null)
            || ($amount !== null && $amount->isNegative())
            || CurrencyCode::tryFrom($currency) === null
            || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
            || trim($coverageStartedAt) === '') {
            throw new InvalidArgumentException('holding_contract_dimension_snapshot_invalid');
        }
    }
}
