<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;

final class ProjectiveTransformFactory
{
    /** @param array<int, array{0: float, 1: float}> $source @param array<int, array{0: float, 1: float}> $destination */
    public function between(array $source, array $destination): ProjectiveTransformData
    {
        $this->validateQuadrilateral($source);
        $this->validateQuadrilateral($destination);
        $forward = $this->solve($source, $destination);
        $inverse = $this->inverse($forward);
        $determinant = $this->determinant($forward);
        $condition = $this->norm($forward) * $this->norm($inverse);

        try {
            return new ProjectiveTransformData($forward, $inverse, $determinant, $condition);
        } catch (\InvalidArgumentException) {
            throw new RasterPreprocessingException('invalid_perspective_transform');
        }
    }

    public function identity(): ProjectiveTransformData
    {
        $matrix = [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];

        return new ProjectiveTransformData($matrix, $matrix, 1.0, 3.0);
    }

    /** @param array<int, array{0: float, 1: float}> $points */
    public function validateQuadrilateral(array $points): void
    {
        if (count($points) !== 4) {
            throw new RasterPreprocessingException('invalid_perspective_quadrilateral');
        }
        foreach ($points as $point) {
            if (count($point) !== 2 || ! is_finite($point[0]) || ! is_finite($point[1])
                || $point[0] < 0.0 || $point[0] > 1.0 || $point[1] < 0.0 || $point[1] > 1.0) {
                throw new RasterPreprocessingException('invalid_perspective_quadrilateral');
            }
        }
        $sign = null;
        for ($i = 0; $i < 4; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % 4];
            $c = $points[($i + 2) % 4];
            $cross = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
            if (! is_finite($cross) || abs($cross) < 1.0e-8) {
                throw new RasterPreprocessingException('singular_perspective_quadrilateral');
            }
            if ($i === 0) {
                $sign = $cross > 0 ? 1 : -1;
            } elseif (($cross > 0 ? 1 : -1) !== $sign) {
                throw new RasterPreprocessingException('self_crossing_perspective_quadrilateral');
            }
        }
        $area = 0.0;
        for ($i = 0; $i < 4; $i++) {
            $next = ($i + 1) % 4;
            $area += $points[$i][0] * $points[$next][1] - $points[$next][0] * $points[$i][1];
        }
        if (abs($area) / 2.0 < 0.0025) {
            throw new RasterPreprocessingException('singular_perspective_quadrilateral');
        }
    }

    /** @param array<int, array{0: float, 1: float}> $source @param array<int, array{0: float, 1: float}> $destination @return array<int, array<int, float>> */
    private function solve(array $source, array $destination): array
    {
        $matrix = [];
        foreach ($source as $i => [$x, $y]) {
            [$u, $v] = $destination[$i];
            $matrix[] = [$x, $y, 1.0, 0.0, 0.0, 0.0, -$u * $x, -$u * $y, $u];
            $matrix[] = [0.0, 0.0, 0.0, $x, $y, 1.0, -$v * $x, -$v * $y, $v];
        }
        for ($column = 0; $column < 8; $column++) {
            $pivot = $column;
            for ($row = $column + 1; $row < 8; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }
            if (abs($matrix[$pivot][$column]) < 1.0e-12) {
                throw new RasterPreprocessingException('singular_perspective_transform');
            }
            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
            $divisor = $matrix[$column][$column];
            for ($item = $column; $item < 9; $item++) {
                $matrix[$column][$item] /= $divisor;
            }
            for ($row = 0; $row < 8; $row++) {
                if ($row === $column) {
                    continue;
                }
                $factor = $matrix[$row][$column];
                for ($item = $column; $item < 9; $item++) {
                    $matrix[$row][$item] -= $factor * $matrix[$column][$item];
                }
            }
        }
        $h = array_map(static fn (array $row): float => $row[8], $matrix);

        return [[$h[0], $h[1], $h[2]], [$h[3], $h[4], $h[5]], [$h[6], $h[7], 1.0]];
    }

    /** @param array<int, array<int, float>> $m @return array<int, array<int, float>> */
    private function inverse(array $m): array
    {
        $det = $this->determinant($m);
        if (abs($det) < 1.0e-12) {
            throw new RasterPreprocessingException('singular_perspective_transform');
        }

        return [
            [($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1]) / $det, ($m[0][2] * $m[2][1] - $m[0][1] * $m[2][2]) / $det, ($m[0][1] * $m[1][2] - $m[0][2] * $m[1][1]) / $det],
            [($m[1][2] * $m[2][0] - $m[1][0] * $m[2][2]) / $det, ($m[0][0] * $m[2][2] - $m[0][2] * $m[2][0]) / $det, ($m[0][2] * $m[1][0] - $m[0][0] * $m[1][2]) / $det],
            [($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]) / $det, ($m[0][1] * $m[2][0] - $m[0][0] * $m[2][1]) / $det, ($m[0][0] * $m[1][1] - $m[0][1] * $m[1][0]) / $det],
        ];
    }

    /** @param array<int, array<int, float>> $m */
    private function determinant(array $m): float
    {
        return $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
            - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
            + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
    }

    /** @param array<int, array<int, float>> $m */
    private function norm(array $m): float
    {
        return sqrt(array_sum(array_map(static fn (array $row): float => array_sum(array_map(static fn (float $v): float => $v * $v, $row)), $m)));
    }
}
