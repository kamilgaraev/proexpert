<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class ScaleCandidateData
{
    public function __construct(
        public string $source,
        public float $metersPerUnit,
        public string $evidenceRef,
        public string $sourceFingerprint,
        public int $pageNumber,
        public string $coordinateTransform,
        public string $runtimeVersion,
        public string $modelVersion,
        public float $confidence,
    ) {
        if (! in_array($source, ['vector', 'vision'], true) || ! is_finite($metersPerUnit) || $metersPerUnit <= 0
            || $evidenceRef === '' || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1
            || $pageNumber < 1 || $coordinateTransform === '' || $runtimeVersion === '' || $modelVersion === ''
            || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Scale candidate is invalid.');
        }
    }

    public function contextKey(): string
    {
        return $this->sourceFingerprint.'|'.$this->pageNumber.'|'.$this->coordinateTransform;
    }
}
