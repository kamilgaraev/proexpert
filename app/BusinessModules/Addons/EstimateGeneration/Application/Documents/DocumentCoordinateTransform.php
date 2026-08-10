<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentCoordinateTransform
{
    private function __construct(
        private float $minX,
        private float $minY,
        private float $width,
        private float $height,
    ) {}

    public static function fromBounds(array $bounds): self
    {
        if (count($bounds) !== 4) {
            throw new InvalidArgumentException('Document source bounds are invalid.');
        }
        $values = array_map(static fn (mixed $value): float => is_int($value) || is_float($value)
            ? (float) $value
            : throw new InvalidArgumentException('Document source bound is invalid.'), $bounds);
        [$minX, $minY, $maxX, $maxY] = $values;
        if (! is_finite($minX) || ! is_finite($minY) || ! is_finite($maxX) || ! is_finite($maxY)
            || $maxX <= $minX || $maxY <= $minY) {
            throw new InvalidArgumentException('Document source bounds are invalid.');
        }

        return new self($minX, $minY, $maxX - $minX, $maxY - $minY);
    }

    public function toNormalized(array $point): array
    {
        [$x, $y] = $this->point($point);

        return [($x - $this->minX) / $this->width, ($y - $this->minY) / $this->height];
    }

    public function toSource(array $point): array
    {
        [$x, $y] = $this->point($point);

        return [$this->minX + ($x * $this->width), $this->minY + ($y * $this->height)];
    }

    private function point(array $point): array
    {
        if (count($point) !== 2 || (! is_int($point[0] ?? null) && ! is_float($point[0] ?? null))
            || (! is_int($point[1] ?? null) && ! is_float($point[1] ?? null))) {
            throw new InvalidArgumentException('Document source point is invalid.');
        }
        $x = (float) $point[0];
        $y = (float) $point[1];
        if (! is_finite($x) || ! is_finite($y)) {
            throw new InvalidArgumentException('Document source point is invalid.');
        }

        return [$x, $y];
    }
}
