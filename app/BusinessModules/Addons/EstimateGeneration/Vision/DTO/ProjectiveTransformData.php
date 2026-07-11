<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use InvalidArgumentException;

final readonly class ProjectiveTransformData
{
    /** @param array<int, array<int, float>> $sourceToDerivative @param array<int, array<int, float>> $derivativeToSource */
    public function __construct(
        public array $sourceToDerivative,
        public array $derivativeToSource,
        public float $determinant,
        public float $condition,
    ) {
        if (! is_finite($determinant) || abs($determinant) < 1.0e-12 || ! is_finite($condition) || $condition > 1.0e12) {
            throw new InvalidArgumentException('Invalid projective transform.');
        }
    }

    /** @param array{0: float, 1: float} $point @return array{0: float, 1: float} */
    public function toDerivative(array $point): array
    {
        return $this->apply($this->sourceToDerivative, $point);
    }

    /** @param array{0: float, 1: float} $point @return array{0: float, 1: float} */
    public function toSource(array $point): array
    {
        return $this->apply($this->derivativeToSource, $point);
    }

    /** @param array<int, array<int, float>> $matrix @param array{0: float, 1: float} $point @return array{0: float, 1: float} */
    private function apply(array $matrix, array $point): array
    {
        [$x, $y] = $point;
        $w = $matrix[2][0] * $x + $matrix[2][1] * $y + $matrix[2][2];
        if (! is_finite($w) || abs($w) < 1.0e-12) {
            throw new InvalidArgumentException('Point is outside projective transform domain.');
        }

        return [
            ($matrix[0][0] * $x + $matrix[0][1] * $y + $matrix[0][2]) / $w,
            ($matrix[1][0] * $x + $matrix[1][1] * $y + $matrix[1][2]) / $w,
        ];
    }
}
