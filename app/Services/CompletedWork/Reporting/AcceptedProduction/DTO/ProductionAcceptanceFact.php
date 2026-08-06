<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use App\Enums\CurrencyCode;
use InvalidArgumentException;

final readonly class ProductionAcceptanceFact
{
    public function __construct(
        public string $plannedQuantity,
        public string $reportedQuantity,
        public string $acceptedQuantityDelta,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public ?int $approvedRateMinor,
        public ?string $currency,
        public ?string $currencySource,
        public ?int $acceptedAmountMinor = null,
    ) {
        foreach ([$plannedQuantity, $reportedQuantity, $acceptedQuantityDelta] as $quantity) {
            if (preg_match('/^-?\d+(?:\.\d{1,4})?$/D', $quantity) !== 1) {
                throw new InvalidArgumentException('production_acceptance_quantity_invalid');
            }
        }

        if (trim($unitDimension) === '' || trim($unitCode) === '' || trim($conversionVersion) === '') {
            throw new InvalidArgumentException('production_acceptance_unit_invalid');
        }

        $moneyFieldsPresent = $approvedRateMinor !== null
            || $acceptedAmountMinor !== null
            || $currency !== null
            || $currencySource !== null;
        if ($moneyFieldsPresent
            && (($approvedRateMinor === null && $acceptedAmountMinor === null)
                || $currency === null
                || CurrencyCode::tryFrom($currency) === null
                || trim((string) $currencySource) === '')
        ) {
            throw new InvalidArgumentException('production_acceptance_money_identity_invalid');
        }
    }
}
