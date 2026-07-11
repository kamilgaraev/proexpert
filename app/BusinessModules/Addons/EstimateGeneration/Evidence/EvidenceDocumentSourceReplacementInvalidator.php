<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EvidenceSourceReplacementInvalidator;

final readonly class EvidenceDocumentSourceReplacementInvalidator implements EvidenceSourceReplacementInvalidator
{
    public function __construct(private EvidenceInvalidator $invalidator) {}

    public function invalidateReplacedDocumentSource(
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        string $previousSourceVersion,
    ): int {
        $sourceRef = 'document:'.$documentId;

        return $this->invalidator->invalidateSources(
            $organizationId,
            $projectId,
            $sessionId,
            [EvidenceSourceType::Document, EvidenceSourceType::DocumentUnit],
            $sourceRef,
            $previousSourceVersion,
            'source_replaced',
        );
    }
}
