<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

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
    ) {
        foreach ([$plannedQuantity, $reportedQuantity, $acceptedQuantityDelta] as $quantity) {
            if (preg_match('/^-?\d+(?:\.\d{1,3})?$/D', $quantity) !== 1) {
                throw new InvalidArgumentException('production_acceptance_quantity_invalid');
            }
        }

        if (trim($unitDimension) === '' || trim($unitCode) === '' || trim($conversionVersion) === '') {
            throw new InvalidArgumentException('production_acceptance_unit_invalid');
        }

        $moneyFieldsPresent = $approvedRateMinor !== null || $currency !== null || $currencySource !== null;
        if ($moneyFieldsPresent
            && ($approvedRateMinor === null
                || $currency === null
                || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
                || trim((string) $currencySource) === '')
        ) {
            throw new InvalidArgumentException('production_acceptance_money_identity_invalid');
        }
    }
}
