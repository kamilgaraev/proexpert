<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($analysis, $document, $pageIds): void {
            $document->drawingElements()->delete();
            $document->quantityTakeoffs()->delete();

            $createdElements = [];

            foreach ($analysis->elements as $element) {
                $sourceRef = $element['source_ref'] ?? [];

                $createdElement = EstimateGenerationDrawingElement::create([
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

                $createdElements[] = [
                    'id' => (int) $createdElement->id,
                    'source_ref' => is_array($sourceRef) ? $sourceRef : [],
                ];
            }

            foreach ($analysis->takeoffs as $takeoff) {
                $sourceRefs = $takeoff['source_refs'] ?? [];
                $firstSourceRef = $sourceRefs[0] ?? [];
                $sourceElementIds = is_array($takeoff['source_element_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $takeoff['source_element_ids'])))
                    : [];

                if ($sourceElementIds === []) {
                    $sourceElementIds = $this->matchingSourceElementIds($takeoff, $createdElements);
                }

                EstimateGenerationQuantityTakeoff::create([
                    'session_id' => $document->session_id,
                    'document_id' => $document->id,
                    'page_id' => $pageIds[(int) ($firstSourceRef['page_number'] ?? 0)] ?? null,
                    'organization_id' => $document->organization_id,
                    'project_id' => $document->project_id,
                    'source_element_ids' => $sourceElementIds,
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
        });

        return $analysis;
    }

    /**
     * @param array<string, mixed> $takeoff
     * @param array<int, array{id: int, source_ref: array<string, mixed>}> $createdElements
     * @return array<int, int>
     */
    private function matchingSourceElementIds(array $takeoff, array $createdElements): array
    {
        $sourceRefs = is_array($takeoff['source_refs'] ?? null)
            ? array_values(array_filter($takeoff['source_refs'], 'is_array'))
            : [];

        if ($sourceRefs === []) {
            return [];
        }

        $ids = [];

        foreach ($sourceRefs as $sourceRef) {
            foreach ($createdElements as $createdElement) {
                if (!$this->sourceRefsMatch($sourceRef, $createdElement['source_ref'])) {
                    continue;
                }

                $ids[] = $createdElement['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sourceRefsMatch(array $left, array $right): bool
    {
        $leftPage = $this->nullableInt($left['page_number'] ?? null);
        $rightPage = $this->nullableInt($right['page_number'] ?? null);

        if ($leftPage !== null && $rightPage !== null && $leftPage !== $rightPage) {
            return false;
        }

        $leftHash = trim((string) ($left['line_hash'] ?? ''));
        $rightHash = trim((string) ($right['line_hash'] ?? ''));

        if ($leftHash !== '' && $rightHash !== '') {
            return hash_equals($leftHash, $rightHash);
        }

        if ($this->sameBbox($left['bbox'] ?? null, $right['bbox'] ?? null)) {
            return true;
        }

        $leftExcerpt = trim((string) ($left['excerpt'] ?? ''));
        $rightExcerpt = trim((string) ($right['excerpt'] ?? ''));

        return $leftExcerpt !== '' && $rightExcerpt !== '' && $leftExcerpt === $rightExcerpt;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function sameBbox(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) {
            return false;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!is_numeric($left[$key] ?? null) || !is_numeric($right[$key] ?? null)) {
                return false;
            }

            if (abs((float) $left[$key] - (float) $right[$key]) > 0.0001) {
                return false;
            }
        }

        return true;
    }
}
