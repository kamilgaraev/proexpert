<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use InvalidArgumentException;

final readonly class RasterPreprocessInput
{
    /** @param array<int, array{0: float, 1: float}>|null $perspectiveQuadrilateral */
    public function __construct(
        public int $organizationId,
        public int $sessionId,
        public int $documentId,
        public int $pageNumber,
        public string $sourceVersion,
        public string $storageKey,
        public string $contentType,
        public int $sourceBytes,
        public string $sourceSha256,
        public string $sourceVersionId,
        public ?array $perspectiveQuadrilateral = null,
        public bool $perspectiveRequired = false,
        public int $maxBytes = 20_000_000,
        public int $maxPixels = 20_000_000,
        public int $maxDimension = 4096,
    ) {
        if ($organizationId < 1 || $sessionId < 1 || $documentId < 1 || $pageNumber < 1
            || $maxBytes < 1 || $maxBytes > 50_000_000 || $maxPixels < 1 || $maxPixels > 50_000_000 || $maxDimension < 64 || $maxDimension > 8192
            || $sourceBytes < 1 || $sourceBytes > $maxBytes
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceVersion) !== 1 || $sourceSha256 !== $sourceVersion
            || trim($sourceVersionId) === ''
            || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new InvalidArgumentException('Invalid raster preprocessing input.');
        }
    }
}
