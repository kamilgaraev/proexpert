<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class EvaluationLineAmount
{
    public function calculate(BigDecimal $quantity, BigDecimal $unitPrice, array $price): string
    {
        if (isset($price['currency_conversion'])) {
            $basis = $price['currency_conversion'];
            if (! is_array($basis) || ! is_string($basis['quantity'] ?? null) || ! $quantity->isEqualTo($basis['quantity'])
                || ($price['currency'] ?? null) !== 'RUB'
                || ! $unitPrice->isEqualTo(BigDecimal::of($basis['source_snapshot']['unit_price'] ?? '-1')->multipliedBy($basis['rate']['rub_per_unit'] ?? '0'))) {
                throw new InvalidArgumentException('evaluation_currency_quantity_or_price_changed');
            }

            return (new EvaluationCurrencyConversion)->money($basis)['quoted_amount'];
        }
        $basis = $price['calculation_basis'] ?? null;
        if ($basis === null) {
            return (string) $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
        }
        // Resource rows are rounded separately by the normative pricing engine.
        // Dividing their sum by work volume and multiplying back loses kopecks.
        if (! is_array($basis) || ($basis['kind'] ?? null) !== 'normative_resource_sum'
            || ($price['source_type'] ?? null) !== 'normative'
            || ! is_string($basis['quantity'] ?? null)
            || ! is_array($basis['resources'] ?? null) || $basis['resources'] === []
            || ! is_string($basis['source_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $basis['source_hash']) !== 1) {
            throw new InvalidArgumentException('evaluation_line_basis_invalid');
        }
        if (! $quantity->isEqualTo(BigDecimal::of($basis['quantity']))) {
            throw new InvalidArgumentException('evaluation_line_basis_quantity_changed');
        }
        $sum = BigDecimal::of($basis['work_cost'] ?? '0')->toScale(2, RoundingMode::Unnecessary);
        foreach ($basis['resources'] as $resource) {
            if (! is_array($resource) || ! is_string($resource['source_reference'] ?? null)
                || $resource['source_reference'] === '' || ! is_string($resource['final_amount'] ?? null)) {
                throw new InvalidArgumentException('evaluation_resource_basis_invalid');
            }
            $amount = BigDecimal::of($resource['final_amount'])->toScale(2, RoundingMode::Unnecessary);
            if ($amount->isLessThan(0)) {
                throw new InvalidArgumentException('evaluation_resource_amount_invalid');
            }
            $sum = $sum->plus($amount);
        }

        return (string) $sum->toScale(2);
    }
}
