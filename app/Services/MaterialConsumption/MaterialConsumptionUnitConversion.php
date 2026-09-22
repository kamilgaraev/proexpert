<?php

declare(strict_types=1);

namespace App\Services\MaterialConsumption;

use App\Exceptions\BusinessLogicException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

use function trans_message;

final class MaterialConsumptionUnitConversion
{
    /**
     * @param  array{coefficient: string, reason: string}|null  $basis
     * @return array{quantity: string, basis: array<string, string>|null}
     */
    public function convert(string $quantity, int $fromUnitId, int $toUnitId, ?array $basis): array
    {
        $quantityNumber = MaterialConsumptionQuantity::of($quantity);
        if (! $quantityNumber->isPositive()) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_quantity_invalid'), 422);
        }

        if ($fromUnitId === $toUnitId) {
            return [
                'quantity' => MaterialConsumptionQuantity::format($quantityNumber),
                'basis' => null,
            ];
        }

        if ($basis === null || ! isset($basis['coefficient'], $basis['reason'])) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_unit_conversion_required'), 422);
        }
        if (! is_string($basis['coefficient']) || ! is_string($basis['reason']) || trim($basis['reason']) === '') {
            throw new BusinessLogicException(trans_message('material_consumption.fact_unit_conversion_required'), 422);
        }

        $coefficient = MaterialConsumptionQuantity::of($basis['coefficient'], 'material_consumption.fact_unit_conversion_required');
        if (! $coefficient->isPositive() || mb_strlen(trim($basis['reason'])) > 2000) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_unit_conversion_required'), 422);
        }

        try {
            $converted = $quantityNumber->multipliedBy($coefficient)->toScale(6, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_quantity_invalid'), 422);
        }

        return [
            'quantity' => MaterialConsumptionQuantity::format($converted),
            'basis' => [
                'coefficient' => (string) $coefficient->stripTrailingZeros(),
                'reason' => trim($basis['reason']),
                'from_unit_id' => (string) $fromUnitId,
                'to_unit_id' => (string) $toUnitId,
            ],
        ];
    }
}
