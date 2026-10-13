<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ConvertEvaluationPriceUnit
{
    public function convert(array $price, ?string $workUnit): ?array
    {
        if ($workUnit === null || ! is_string($price['unit'] ?? null) || ! is_string($price['unit_price'] ?? null)) {
            return null;
        }
        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($workUnit, $price['unit']);
        if ($factor === null) {
            return null;
        }

        $quantity = is_string($price['price_quantity'] ?? null) ? BigDecimal::of($price['price_quantity'])->dividedBy($factor, 18, RoundingMode::Unnecessary) : null;

        return [...$price, ...($quantity === null ? [] : ['source_price_quantity' => $price['price_quantity'], 'price_quantity' => (string) $quantity->strippedOfTrailingZeros()]),
            'source_unit' => $price['unit'], 'source_unit_price' => $price['unit_price'],
            'unit_conversion_factor' => $factor, 'unit' => $workUnit,
            'unit_price' => (string) BigDecimal::of($price['unit_price'])->multipliedBy($factor)];
    }
}
