<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;

final class DrawingGeometryAnalyzer
{
    public function __construct(private readonly BuildingQuantityCalculator $calculator = new BuildingQuantityCalculator) {}

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
        $models = [];
        $modelPages = [];

        foreach ($recognition->pages as $page) {
            $model = $page->rawPayload['normalized_building_model'] ?? null;
            if (is_array($model)) {
                $models[] = $model;
                $modelPages[] = $page->pageNumber;
            }
        }

        $quantities = [];
        $reviewReasons = [];
        if (count($models) === 1) {
            $calculation = $this->calculator->calculate($models[0]);
            $quantities = $calculation->toArray()['quantities'];
            $reviewReasons = array_values(array_unique(array_map(
                static fn (array $diagnostic): string => $diagnostic['code'],
                array_filter($calculation->diagnostics, static fn (array $diagnostic): bool => $diagnostic['severity'] === 'blocking')
            )));
        } elseif (count($models) > 1) {
            $reviewReasons = ['multiple_normalized_building_models'];
        } else {
            $reviewReasons = ['normalized_building_model_missing'];
            $modelPages = array_map(static fn ($page): int => $page->pageNumber, $recognition->pages);
        }

        sort($reviewReasons, SORT_STRING);

        return [
            'elements' => [],
            'quantities' => $quantities,
            'metrics' => [
                'page_count' => count($recognition->pages), 'geometry_metrics_status' => 'unavailable',
                'line_count' => 0, 'curve_count' => 0, 'rect_count' => 0,
                'vector_element_count' => 0, 'contour_candidate_count' => 0,
                'table_candidate_count' => 0, 'title_block_candidate_count' => 0,
            ],
            'page_metrics' => [],
            'review_reasons' => $reviewReasons,
            'review_required_pages' => $reviewReasons === [] ? [] : $modelPages,
        ];
    }
}
