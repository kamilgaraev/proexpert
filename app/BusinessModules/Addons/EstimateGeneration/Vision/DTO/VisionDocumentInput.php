<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetEvidence;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetRecheckScope;
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
        public ?TargetedSheetRecheckScope $recheckScope = null,
        /** @var list<string> */
        public array $nativeReferences = [],
        /** @var list<TargetedSheetEvidence> */
        public array $supplementalEvidence = [],
        public ?string $auxiliaryText = null,
        /** @var array<string, mixed> */
        public array $auxiliaryMetadata = [],
        public ?VisionAnalysisData $primaryAnalysis = null,
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
            || ($recheckScope !== null && $recheckScope->role !== $sheetRole)
            || ($primaryAnalysis !== null && $recheckScope === null)
            || count($nativeReferences) > 20_000
            || count($nativeReferences) !== count(array_unique($nativeReferences))
            || count($supplementalEvidence) > 1
            || ($recheckScope === null && $supplementalEvidence !== [])
            || ($recheckScope?->entityKey !== null && $supplementalEvidence !== [])
            || ($recheckScope !== null && $recheckScope->entityKey === null && count($supplementalEvidence) !== 1)
            || ($auxiliaryText !== null && (mb_strlen($auxiliaryText) > 12_000 || str_contains($auxiliaryText, "\0")))
            || $auxiliaryMetadataBytes > 20_000
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
        foreach ($supplementalEvidence as $evidence) {
            if (! $evidence instanceof TargetedSheetEvidence
                || $evidence->organizationId !== $organizationId
                || $evidence->projectId !== $projectId
                || $evidence->sessionId !== $sessionId
                || $evidence->source() === sprintf('document:%d/sheet:%d', $documentId, $pageId)
                || ! in_array($evidence->source(), $recheckScope?->sourceSet ?? [], true)) {
                throw new InvalidArgumentException('Invalid supplemental sheet evidence.');
            }
        }
    }

    public function isTargetedSheetReanalysis(): bool
    {
        return $this->recheckScope !== null;
    }
}
