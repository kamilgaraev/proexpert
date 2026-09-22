<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Exceptions\BusinessLogicException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final class WorkVolumeCoverageConversion
{
    private const QUANTITY_PATTERN = '/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/D';
    private const COEFFICIENT_PATTERN = '/^[0-9]{1,12}(?:\.[0-9]{1,12})?$/D';

    public function convert(string $quantity, string $fromUnit, string $toUnit, ?array $basis): array
    {
        $quantityNumber = $this->decimal($quantity, self::QUANTITY_PATTERN, 'budget_estimates.work_volume_statements.coverage_quantity_invalid');
        if (! $quantityNumber->isPositive()) {
            $this->fail('budget_estimates.work_volume_statements.coverage_quantity_invalid');
        }

        if ($fromUnit === $toUnit) {
            if ($basis !== null) {
                $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
            }
            return ['quantity' => $this->formatQuantity($quantityNumber), 'basis' => null];
        }

        if ($basis === null || array_diff(array_keys($basis), ['coefficient', 'reason', 'source_link_id']) !== []) {
            $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
        }
        if (! isset($basis['coefficient'], $basis['reason']) || ! is_string($basis['coefficient']) || ! is_string($basis['reason'])) {
            $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
        }
        $coefficient = $this->decimal($basis['coefficient'], self::COEFFICIENT_PATTERN, 'budget_estimates.work_volume_statements.coverage_unit_conversion_required');
        if (! $coefficient->isPositive() || trim($basis['reason']) === '' || mb_strlen($basis['reason']) > 2000) {
            $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
        }

        $normalizedBasis = [
            'coefficient' => $coefficient->strippedOfTrailingZeros()->toString(),
            'reason' => trim($basis['reason']),
            'from_unit' => $fromUnit,
            'to_unit' => $toUnit,
        ];
        if (array_key_exists('source_link_id', $basis)) {
            $sourceLinkId = $basis['source_link_id'];
            if ((is_int($sourceLinkId) && $sourceLinkId <= 0) || (! is_int($sourceLinkId) && (! is_string($sourceLinkId) || ! preg_match('/^[1-9][0-9]*$/D', $sourceLinkId)))) {
                $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
            }
            $sourceLinkIdString = (string) $sourceLinkId;
            $max = (string) PHP_INT_MAX;
            if (strlen($sourceLinkIdString) > strlen($max) || (strlen($sourceLinkIdString) === strlen($max) && strcmp($sourceLinkIdString, $max) > 0)) {
                $this->fail('budget_estimates.work_volume_statements.coverage_unit_conversion_required');
            }
            $normalizedBasis['source_link_id'] = (int) $sourceLinkId;
        }

        try {
            $converted = $quantityNumber->multipliedBy($coefficient)->toScale(6, RoundingMode::Unnecessary);
        } catch (MathException) {
            $this->fail('budget_estimates.work_volume_statements.coverage_quantity_invalid');
        }
        return ['quantity' => $this->formatQuantity($converted), 'basis' => $normalizedBasis];
    }

    private function decimal(string $value, string $pattern, string $errorKey): BigDecimal
    {
        if (! preg_match($pattern, $value)) {
            $this->fail($errorKey);
        }
        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            $this->fail($errorKey);
        }
    }

    private function formatQuantity(BigDecimal $value): string
    {
        try {
            $formatted = $value->toScale(6, RoundingMode::Unnecessary)->toString();
        } catch (MathException) {
            $this->fail('budget_estimates.work_volume_statements.coverage_quantity_invalid');
        }
        if (! preg_match('/^([0-9]+)\.[0-9]{6}$/D', $formatted, $matches) || strlen(ltrim($matches[1], '0') ?: '0') > 18) {
            $this->fail('budget_estimates.work_volume_statements.coverage_quantity_invalid');
        }
        return $formatted;
    }

    private function fail(string $key): never
    {
        throw new BusinessLogicException(trans_message($key), 422);
    }
}
