<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use Closure;
use InvalidArgumentException;
use JsonException;

final readonly class VisionDocumentInput
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
        public string $imageDetail,
        public AiOperationContext $operationContext,
        public ProjectiveTransformData $sourceTransform,
        public string $sheetRole = 'unknown',
        /** @var list<string> */
        public array $nativeReferences = [],
        public ?string $auxiliaryText = null,
        /** @var array<string, mixed> */
        public array $auxiliaryMetadata = [],
        /** @var list<array<string, mixed>> */
        public array $regionImages = [],
        public ?Closure $onPhysicalAttemptReserved = null,
    ) {
        $dimensions = @getimagesizefromstring($imageContent);
        $detectedMime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
        try {
            $auxiliaryMetadataBytes = strlen(json_encode($auxiliaryMetadata, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $auxiliaryMetadataBytes = PHP_INT_MAX;
        }
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || $documentId < 1 || $pageId < 1
            || $pageNumber < 1 || $pageNumber > 10_000 || $processingUnitId < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceVersion) !== 1
            || ! hash_equals($derivativeHash, 'sha256:'.hash('sha256', $imageContent))
            || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_string($detectedMime) || $detectedMime !== $contentType
            || $imageContent === '' || strlen($imageContent) > 20_000_000
            || ! in_array($imageDetail, ['low', 'high', 'auto'], true)
            || ! in_array($sheetRole, ['plan', 'section', 'facade', 'explication', 'specification', 'unknown'], true)
            || count($nativeReferences) > 20_000
            || count($nativeReferences) !== count(array_unique($nativeReferences))
            || ($auxiliaryText !== null && (mb_strlen($auxiliaryText) > 12_000 || str_contains($auxiliaryText, "\0")))
            || $auxiliaryMetadataBytes > (array_key_exists('arbitration', $auxiliaryMetadata) ? 196_608 : 20_000)
            || count($regionImages) > 8
            || $operationContext->organizationId !== $organizationId
            || $operationContext->projectId !== $projectId
            || $operationContext->sessionId !== $sessionId
            || $operationContext->documentId !== $documentId
            || $operationContext->pageId !== $pageId
            || $operationContext->unitId !== $processingUnitId
            || $operationContext->operation !== 'vision') {
            throw new InvalidArgumentException('Invalid vision document input.');
        }
        foreach ($nativeReferences as $nativeReference) {
            if (! is_string($nativeReference) || mb_strlen($nativeReference) > 240
                || preg_match('~^(?:pdf|image|cad|xlsx):(?!.*\\\\)[^\x00-\x1F]{1,220}$~u', $nativeReference) !== 1) {
                throw new InvalidArgumentException('Invalid vision native reference registry.');
            }
        }
        $regionBytes = 0;
        foreach ($regionImages as $region) {
            $keys = is_array($region) ? array_keys($region) : [];
            sort($keys);
            if ($keys !== ['box', 'content_type', 'id', 'image_content', 'label', 'purpose', 'sha256']
                || ! is_string($region['id']) || preg_match('/^region:[a-f0-9]{24}$/D', $region['id']) !== 1
                || ! is_string($region['label']) || trim($region['label']) === '' || mb_strlen($region['label']) > 160
                || ! is_string($region['purpose']) || trim($region['purpose']) === '' || mb_strlen($region['purpose']) > 160
                || $region['content_type'] !== 'image/png'
                || ! is_string($region['image_content']) || $region['image_content'] === ''
                || $region['sha256'] !== 'sha256:'.hash('sha256', $region['image_content'])
                || ! is_array($region['box']) || count($region['box']) !== 4) {
                throw new InvalidArgumentException('Invalid vision semantic region image.');
            }
            $regionBytes += strlen($region['image_content']);
        }
        if ($regionBytes > 12_000_000) {
            throw new InvalidArgumentException('Vision semantic region byte budget exceeded.');
        }
    }

    /** @param list<array<string, mixed>> $regionImages */
    public function withRegionImages(array $regionImages): self
    {
        return new self(
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->documentId,
            $this->pageId,
            $this->pageNumber,
            $this->processingUnitId,
            $this->sourceVersion,
            $this->derivativeHash,
            $this->contentType,
            $this->imageContent,
            $this->imageDetail,
            $this->operationContext,
            $this->sourceTransform,
            $this->sheetRole,
            $this->nativeReferences,
            $this->auxiliaryText,
            $this->auxiliaryMetadata,
            $regionImages,
            $this->onPhysicalAttemptReserved,
        );
    }
}
