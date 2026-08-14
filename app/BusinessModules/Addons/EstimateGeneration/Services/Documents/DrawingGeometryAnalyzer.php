<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

final class DrawingGeometryAnalyzer
{
    /**
     * Compatibility boundary: document metadata is intentionally ignored. Only a normalized metric model may produce quantities.
     *
     * @return array{
     *     elements: array<int, array<string, mixed>>,
     *     quantities: array<int, array<string, mixed>>,
     *     metrics: array{page_count: int, geometry_metrics_status: string, line_count: int, curve_count: int, rect_count: int, vector_element_count: int, contour_candidate_count: int, table_candidate_count: int, title_block_candidate_count: int},
     *     page_metrics: array<int, array<string, mixed>>,
     *     review_reasons: array<int, string>,
     *     review_required_pages: array<int, int>
     * }
     */
    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): array
    {
        unset($documentId, $filename);
        $elements = [];
        $quantities = [];

        foreach ($recognition->pages as $page) {
            foreach ($this->list($page->rawPayload['drawing_elements'] ?? null) as $element) {
                $elements[] = [...$element, 'page_number' => $page->pageNumber];
            }
            foreach ($this->list($page->rawPayload['quantity_takeoffs'] ?? null) as $takeoff) {
                if (! $this->validTakeoff($takeoff)) {
                    continue;
                }
                $quantities[] = [
                    'key' => (string) ($takeoff['scope_key'] ?? $takeoff['name']),
                    'amount' => (string) $takeoff['quantity'],
                    'unit' => (string) $takeoff['unit'],
                    'evidence_ids' => array_values(array_filter(
                        is_array($takeoff['source_refs'] ?? null) ? $takeoff['source_refs'] : [],
                        'is_string',
                    )),
                ];
            }
        }

        $reviewReasons = $quantities === [] ? ['canonical_observations_missing'] : [];
        $reviewPages = $reviewReasons === []
            ? []
            : array_map(static fn ($page): int => $page->pageNumber, $recognition->pages);

        return [
            'elements' => $elements,
            'quantities' => $quantities,
            'metrics' => [
                'page_count' => count($recognition->pages), 'geometry_metrics_status' => 'unavailable',
                'line_count' => 0, 'curve_count' => 0, 'rect_count' => 0,
                'vector_element_count' => 0, 'contour_candidate_count' => 0,
                'table_candidate_count' => 0, 'title_block_candidate_count' => 0,
            ],
            'page_metrics' => [],
            'review_reasons' => $reviewReasons,
            'review_required_pages' => $reviewPages,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string, mixed> $takeoff */
    private function validTakeoff(array $takeoff): bool
    {
        return is_string($takeoff['name'] ?? null)
            && $takeoff['name'] !== ''
            && is_string($takeoff['unit'] ?? null)
            && $takeoff['unit'] !== ''
            && is_numeric($takeoff['quantity'] ?? null)
            && (float) $takeoff['quantity'] >= 0
            && is_finite((float) $takeoff['quantity']);
    }
}
