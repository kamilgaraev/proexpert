<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DomainException;

final class ExecutiveDocumentCoverageDeclaration
{
    private const DECIMAL_PATTERN = '/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/D';

    public function normalizeScope(array $scope): array
    {
        $references = ['project_id', 'work_type_id', 'project_location_id', 'completed_work_id'];
        if (array_diff(array_keys($scope), [...$references, 'quantity', 'measurement_unit_id']) !== []) {
            $this->invalid();
        }
        foreach ([...$references, 'measurement_unit_id'] as $key) {
            if (! array_key_exists($key, $scope)) {
                continue;
            }
            if ($scope[$key] === null && $key !== 'measurement_unit_id') {
                unset($scope[$key]);
                continue;
            }
            if ((! is_int($scope[$key]) && ! is_string($scope[$key]))
                || filter_var($scope[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $this->invalid();
            }
            $scope[$key] = (int) $scope[$key];
        }
        if (array_key_exists('quantity', $scope) || array_key_exists('measurement_unit_id', $scope)) {
            $quantity = $scope['quantity'] ?? null;
            $unit = $scope['measurement_unit_id'] ?? 0;
            $normalized = $this->normalize(['quantity' => $quantity, 'measurement_unit_id' => $unit], is_string($quantity) ? $quantity : '', $unit);
            $scope = array_replace($scope, $normalized);
        }
        return $scope;
    }

    public function covers(array $coverage, array $scope): bool
    {
        if ($scope === []) {
            return false;
        }
        foreach ($scope as $key => $expected) {
            if ($expected === null || $key === 'quantity') {
                continue;
            }
            if (($coverage[$key] ?? null) !== $expected) {
                return false;
            }
        }
        if (isset($scope['quantity'])) {
            if (! is_string($coverage['quantity'] ?? null) || ! is_int($coverage['measurement_unit_id'] ?? null)) {
                return false;
            }
            try {
                $this->normalize(['quantity' => $scope['quantity'], 'measurement_unit_id' => $scope['measurement_unit_id'] ?? null], $coverage['quantity'], $coverage['measurement_unit_id']);
            } catch (DomainException) {
                return false;
            }
        }
        return true;
    }

    public function normalize(array $declaration, string $sourceQuantity, int $sourceUnitId): array
    {
        if ($declaration === []) {
            return [];
        }
        if (array_diff(array_keys($declaration), ['quantity', 'measurement_unit_id']) !== []
            || ! array_key_exists('quantity', $declaration)
            || ! array_key_exists('measurement_unit_id', $declaration)
            || ! is_string($declaration['quantity'])
            || ! is_int($declaration['measurement_unit_id'])
            || $declaration['measurement_unit_id'] <= 0
            || $sourceUnitId <= 0) {
            $this->invalid();
        }
        if (! preg_match(self::DECIMAL_PATTERN, $sourceQuantity) || ! preg_match(self::DECIMAL_PATTERN, $declaration['quantity'])) {
            $this->invalid();
        }
        try {
            $source = BigDecimal::of($sourceQuantity);
            $quantity = BigDecimal::of($declaration['quantity']);
        } catch (MathException) {
            $this->invalid();
        }
        if (! $source->isPositive() || ! $quantity->isPositive()
            || $declaration['measurement_unit_id'] !== $sourceUnitId
            || $quantity->isGreaterThan($source)) {
            $this->invalid();
        }
        return [
            'quantity' => $quantity->strippedOfTrailingZeros()->toString(),
            'measurement_unit_id' => $declaration['measurement_unit_id'],
        ];
    }

    private function invalid(): never
    {
        throw new DomainException('invalid_documentary_coverage_declaration', 422);
    }
}
