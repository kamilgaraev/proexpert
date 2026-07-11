<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use InvalidArgumentException;

final readonly class VisionDocumentInput
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $documentId,
        public int $pageId,
        public string $sourceVersion,
        public string $derivativeHash,
        public string $contentType,
        public string $imageContent,
        public string $imageDetail,
        public AiOperationContext $operationContext,
        public ProjectiveTransformData $sourceTransform,
    ) {
        $dimensions = @getimagesizefromstring($imageContent);
        $detectedMime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || $documentId < 1 || $pageId < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceVersion) !== 1
            || ! hash_equals($derivativeHash, 'sha256:'.hash('sha256', $imageContent))
            || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_string($detectedMime) || $detectedMime !== $contentType
            || $imageContent === '' || strlen($imageContent) > 20_000_000
            || ! in_array($imageDetail, ['low', 'high', 'auto'], true)
            || $operationContext->organizationId !== $organizationId
            || $operationContext->projectId !== $projectId
            || $operationContext->sessionId !== $sessionId
            || $operationContext->documentId !== $documentId
            || $operationContext->pageId !== $pageId
            || $operationContext->operation !== 'vision') {
            throw new InvalidArgumentException('Invalid vision document input.');
        }
    }
}
