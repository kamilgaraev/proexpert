<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use Closure;
use RuntimeException;

final class ObserverInputBuilder
{
    public function build(
        VisionDocumentInput $source,
        ObserverProfile $profile,
        Closure $onPhysicalAttemptReserved,
    ): VisionDocumentInput {
        $contextSeed = implode('|', [
            $source->operationContext->correlationId,
            $source->sourceVersion,
            $source->derivativeHash,
            $profile->value,
            $profile->promptHash(),
        ]);
        $correlationId = AiOperationContext::deterministicId('observer-correlation|'.$contextSeed);
        $attemptId = AiOperationContext::deterministicId('observer-attempt|'.$contextSeed);
        $imageContent = $this->composeImage($source->imageContent, $profile);
        $trustedMetadata = array_intersect_key($source->auxiliaryMetadata, array_flip([
            'representation_status',
            'geometry_status',
            'capabilities',
            'source_bounds',
            'native_text_truncated',
        ]));

        return new VisionDocumentInput(
            organizationId: $source->organizationId,
            projectId: $source->projectId,
            sessionId: $source->sessionId,
            documentId: $source->documentId,
            pageId: $source->pageId,
            pageNumber: $source->pageNumber,
            processingUnitId: $source->processingUnitId,
            sourceVersion: $source->sourceVersion,
            derivativeHash: 'sha256:'.hash('sha256', $imageContent),
            contentType: 'image/png',
            imageContent: $imageContent,
            imageDetail: $source->imageDetail,
            operationContext: new AiOperationContext(
                $correlationId,
                $attemptId,
                $source->organizationId,
                $source->projectId,
                $source->sessionId,
                'understand_documents',
                'vision',
                1,
                $source->documentId,
                $source->pageId,
                $source->processingUnitId,
            ),
            sourceTransform: $source->sourceTransform,
            sheetRole: 'unknown',
            nativeReferences: $source->nativeReferences,
            auxiliaryText: $source->auxiliaryText,
            auxiliaryMetadata: [
                ...$trustedMetadata,
                'observer' => [
                    'profile' => $profile->value,
                    'role' => $profile->role()->value,
                    'prompt_contract' => $profile->promptContractVersion(),
                    'prompt_sha256' => $profile->promptHash(),
                    'composition' => $profile->composition(),
                    'max_claims' => 64,
                    'max_evidence' => 128,
                ],
            ],
            regionImages: $profile === ObserverProfile::Literal ? [] : $source->regionImages,
            onPhysicalAttemptReserved: $onPhysicalAttemptReserved,
        );
    }

    private function composeImage(string $source, ObserverProfile $profile): string
    {
        $image = @imagecreatefromstring($source);
        if (! $image instanceof \GdImage) {
            throw new RuntimeException('observer_image_composition_invalid');
        }
        imagesavealpha($image, true);
        ob_start();
        $encoded = imagepng($image, null, match ($profile) {
            ObserverProfile::Literal => 3,
            ObserverProfile::Construction => 6,
            ObserverProfile::Risk => 9,
        });
        $content = ob_get_clean();
        imagedestroy($image);
        if (! $encoded || ! is_string($content) || $content === '' || strlen($content) > 20_000_000) {
            throw new RuntimeException('observer_image_composition_invalid');
        }

        return $content;
    }
}
