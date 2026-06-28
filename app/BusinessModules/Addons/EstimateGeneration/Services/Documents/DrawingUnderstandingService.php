<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;

final class DrawingUnderstandingService
{
    public function __construct(
        private readonly DrawingAnalysisProviderInterface $provider,
    ) {}

    public function analyzeAndPersist(
        EstimateGenerationDocument $document,
        OcrRecognitionResult $recognition,
        array $pageIds
    ): DrawingAnalysisResultData {
        $analysis = $this->provider->analyze((int) $document->id, $document->filename, $recognition);

        $document->drawingElements()->delete();
        $document->quantityTakeoffs()->delete();

        foreach ($analysis->elements as $element) {
            $sourceRef = $element['source_ref'] ?? [];

            EstimateGenerationDrawingElement::create([
                'session_id' => $document->session_id,
                'document_id' => $document->id,
                'page_id' => $pageIds[(int) ($sourceRef['page_number'] ?? 0)] ?? null,
                'organization_id' => $document->organization_id,
                'project_id' => $document->project_id,
                'type' => $element['type'],
                'label' => $element['label'] ?? null,
                'value_text' => $element['value_text'] ?? null,
                'value_number' => $element['value_number'] ?? null,
                'unit' => $element['unit'] ?? null,
                'bbox' => $element['bbox'] ?? null,
                'geometry' => $element['geometry'] ?? null,
                'confidence' => $element['confidence'] ?? 0.7,
                'source_ref' => $sourceRef,
                'normalized_payload' => $element['normalized_payload'] ?? [],
            ]);
        }

        foreach ($analysis->takeoffs as $takeoff) {
            $sourceRefs = $takeoff['source_refs'] ?? [];
            $firstSourceRef = $sourceRefs[0] ?? [];

            EstimateGenerationQuantityTakeoff::create([
                'session_id' => $document->session_id,
                'document_id' => $document->id,
                'page_id' => $pageIds[(int) ($firstSourceRef['page_number'] ?? 0)] ?? null,
                'organization_id' => $document->organization_id,
                'project_id' => $document->project_id,
                'source_element_ids' => $takeoff['source_element_ids'] ?? [],
                'scope_key' => $takeoff['scope_key'] ?? null,
                'work_intent' => $takeoff['work_intent'] ?? [],
                'name' => $takeoff['name'],
                'unit' => $takeoff['unit'],
                'quantity' => $takeoff['quantity'],
                'formula' => $takeoff['formula'] ?? null,
                'confidence' => $takeoff['confidence'] ?? 0.7,
                'source_refs' => $sourceRefs,
                'normalized_payload' => $takeoff['normalized_payload'] ?? [],
            ]);
        }

        return $analysis;
    }
}
