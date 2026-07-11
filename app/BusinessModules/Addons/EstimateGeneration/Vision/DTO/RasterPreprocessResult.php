<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

final readonly class RasterPreprocessResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $derivativeStorageKey,
        public string $derivativeHash,
        public string $derivativeVersion,
        public int $sourceWidth,
        public int $sourceHeight,
        public int $outputWidth,
        public int $outputHeight,
        public float $sharpness,
        public float $dynamicRange,
        public float $blankRatio,
        public float $clippingRatio,
        public ?float $skewDegrees,
        public string $perspectiveStatus,
        public ProjectiveTransformData $transform,
        public array $warnings,
    ) {}
}
