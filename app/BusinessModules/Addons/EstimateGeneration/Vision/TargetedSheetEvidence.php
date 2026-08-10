<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use InvalidArgumentException;

final readonly class TargetedSheetEvidence
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $documentId,
        public int $pageId,
        public int $pageNumber,
        public int $processingUnitId,
        public string $sourceVersion,
        public string $derivativeHash,
        public string $contentType,
        public string $imageContent,
    ) {
        $dimensions = @getimagesizefromstring($imageContent);
        if (min($organizationId, $projectId, $sessionId, $documentId, $pageId, $pageNumber, $processingUnitId) < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceVersion) !== 1
            || ! hash_equals($derivativeHash, 'sha256:'.hash('sha256', $imageContent))
            || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_array($dimensions) || ($dimensions['mime'] ?? null) !== $contentType
            || $imageContent === '' || strlen($imageContent) > 20_000_000) {
            throw new InvalidArgumentException('Invalid targeted sheet evidence.');
        }
    }

    public function source(): string
    {
        return sprintf('document:%d/sheet:%d', $this->documentId, $this->pageId);
    }

    /** @return array<string, int|string> */
    public function locator(): array
    {
        return [
            'page_id' => $this->pageId,
            'page_number' => $this->pageNumber,
            'processing_unit_id' => $this->processingUnitId,
            'source_version' => $this->sourceVersion,
            'coordinate_space' => 'normalized_derivative_v1',
        ];
    }
}
