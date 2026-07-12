<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class ScaleContextData
{
    public function __construct(
        public string $sourceFingerprint,
        public int $pageNumber,
        public string $coordinateTransform,
        public string $coordinateSpace,
    ) {
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1 || $pageNumber < 1
            || $coordinateTransform === '' || $coordinateSpace === '') {
            throw new InvalidArgumentException('Scale context is invalid.');
        }
    }

    public function key(): string
    {
        return implode('|', [$this->sourceFingerprint, $this->pageNumber, $this->coordinateTransform, $this->coordinateSpace]);
    }
}
