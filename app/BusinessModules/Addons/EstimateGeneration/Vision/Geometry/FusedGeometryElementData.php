<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class FusedGeometryElementData
{
    public function __construct(
        public string $key,
        public string $type,
        public array $geometry,
        public string $sourceType,
        public string $evidenceRef,
        public string $sourceFingerprint,
        public int $pageNumber,
        public string $coordinateSpace,
        public string $runtimeVersion,
        public string $modelVersion,
        public float $confidence,
    ) {
        if ($key === '' || ! in_array($type, ['room', 'wall', 'opening', 'engineering_element'], true)
            || ! in_array($sourceType, ['vector', 'vision'], true) || $evidenceRef === ''
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1 || $pageNumber < 1
            || $coordinateSpace === '' || $runtimeVersion === '' || $modelVersion === ''
            || ! is_finite($confidence) || $confidence < 0 || $confidence > 1 || $geometry === []) {
            throw new InvalidArgumentException('Fused geometry element is invalid.');
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
