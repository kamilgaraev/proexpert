<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use InvalidArgumentException;

final readonly class VisionGeometryExpertModel implements GeometryExpertModel
{
    public function __construct(private VisionProvider $vision) {}

    public function interpret(GeometryExpertInput $input, callable $onPhysicalAttemptReserved): array
    {
        $results = [];
        foreach ($input->sheets as $sheet) {
            if (! is_array($sheet) || ! in_array($sheet['sheet_role'] ?? null, [
                'plan', 'section', 'facade', 'roof', 'explication', 'specification',
            ], true)) {
                continue;
            }
            $source = $sheet['source'] ?? null;
            $arbitration = $sheet['arbitration'] ?? null;
            if (! $source instanceof VisionDocumentInput || ! is_array($arbitration)) {
                throw new InvalidArgumentException('geometry_expert_sheet_source_invalid');
            }
            $analysis = $this->vision->analyze(new VisionDocumentInput(
                organizationId: $source->organizationId,
                projectId: $source->projectId,
                sessionId: $source->sessionId,
                documentId: $source->documentId,
                pageId: $source->pageId,
                pageNumber: $source->pageNumber,
                processingUnitId: $source->processingUnitId,
                sourceVersion: $source->sourceVersion,
                derivativeHash: $source->derivativeHash,
                contentType: $source->contentType,
                imageContent: $source->imageContent,
                imageDetail: $source->imageDetail,
                operationContext: $source->operationContext,
                sourceTransform: $source->sourceTransform,
                sheetRole: $source->sheetRole,
                nativeReferences: $source->nativeReferences,
                auxiliaryText: $source->auxiliaryText,
                auxiliaryMetadata: [
                    'geometry_expert' => [
                        'contract' => RunGeometryExpert::PROMPT_CONTRACT,
                        'source_version' => $source->sourceVersion,
                        'arbitration' => $arbitration,
                    ],
                ],
                onPhysicalAttemptReserved: $onPhysicalAttemptReserved(...),
            ));
            $results[] = [
                'sheet_id' => is_string($sheet['sheet_id'] ?? null) ? $sheet['sheet_id'] : 'page:'.$source->pageId,
                'sheet_role' => $sheet['sheet_role'],
                'page_number' => $source->pageNumber,
                'interpretations' => $analysis->rawObserverFacts,
            ];
        }

        return $results;
    }
}
