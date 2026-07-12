<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class ControlDimensionData
{
    public float $metersPerUnit;

    public function __construct(
        public array $pixelStart,
        public array $pixelEnd,
        public float $meters,
        public int $confirmedBy,
        public string $evidenceRef,
        public string $sourceFingerprint,
        public int $pageNumber,
        public string $coordinateTransform,
        public string $coordinateSpace = 'source_units_v1',
    ) {
        foreach ([$pixelStart, $pixelEnd] as $point) {
            if (count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1]) || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])) {
                throw new InvalidArgumentException('Control dimension point is invalid.');
            }
        }
        $distance = hypot((float) $pixelEnd[0] - (float) $pixelStart[0], (float) $pixelEnd[1] - (float) $pixelStart[1]);
        if ($distance <= 0 || ! is_finite($meters) || $meters <= 0 || $confirmedBy < 1 || $evidenceRef === ''
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1 || $pageNumber < 1 || $coordinateTransform === '' || $coordinateSpace === '') {
            throw new InvalidArgumentException('Control dimension is invalid.');
        }
        $this->metersPerUnit = $meters / $distance;
    }

    public function contextKey(): string
    {
        return $this->context()->key();
    }

    public function context(): ScaleContextData
    {
        return new ScaleContextData($this->sourceFingerprint, $this->pageNumber, $this->coordinateTransform, $this->coordinateSpace);
    }
}
