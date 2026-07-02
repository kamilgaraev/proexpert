<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

final class DrawingGeometryAnalyzer
{
    /**
     * @return array{
     *     elements: array<int, array<string, mixed>>,
     *     metrics: array<string, int|float>,
     *     page_metrics: array<int, array<string, mixed>>,
     *     review_reasons: array<int, string>,
     *     review_required_pages: array<int, int>
     * }
     */
    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): array
    {
        $elements = [];
        $pageMetrics = [];
        $reviewReasons = [];
        $reviewRequiredPages = [];
        $totals = [
            'page_count' => 0,
            'line_count' => 0,
            'curve_count' => 0,
            'rect_count' => 0,
            'vector_element_count' => 0,
            'contour_candidate_count' => 0,
            'table_candidate_count' => 0,
            'title_block_candidate_count' => 0,
        ];

        foreach ($recognition->pages as $page) {
            $geometry = $this->pageGeometry($page);

            if ($geometry === []) {
                continue;
            }

            $metrics = $this->metrics($geometry);
            $signals = $this->signals($geometry);
            $role = $this->pageRole($geometry);
            $pageNumber = $page->pageNumber;
            $pageReasons = $this->reviewReasons($page, $role, $metrics, $signals);

            $totals['page_count']++;

            foreach ($metrics as $metric => $value) {
                if (array_key_exists($metric, $totals) && is_numeric($value)) {
                    $totals[$metric] += (float) $value;
                }
            }

            $pageMetrics[$pageNumber] = [
                'page_number' => $pageNumber,
                'page_role' => $role,
                'signals' => $signals,
                'visual_metrics' => $metrics,
                'review_reasons' => $pageReasons,
                'requires_review' => $pageReasons !== [],
            ];

            if ($pageReasons !== []) {
                $reviewRequiredPages[] = $pageNumber;
                array_push($reviewReasons, ...$pageReasons);
            }

            array_push(
                $elements,
                ...$this->metricElements($documentId, $filename, $page, $geometry, $metrics, $role, $signals, $pageReasons),
                ...$this->candidateElements($documentId, $filename, $page, $geometry, $metrics, $role)
            );
        }

        return [
            'elements' => $elements,
            'metrics' => $totals,
            'page_metrics' => $pageMetrics,
            'review_reasons' => array_values(array_unique($reviewReasons)),
            'review_required_pages' => array_values(array_unique($reviewRequiredPages)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageGeometry(OcrPageResult $page): array
    {
        return is_array($page->rawPayload['geometry'] ?? null) ? $page->rawPayload['geometry'] : [];
    }

    /**
     * @param array<string, mixed> $geometry
     * @return array<string, int|float>
     */
    private function metrics(array $geometry): array
    {
        $metrics = is_array($geometry['visual_metrics'] ?? null) ? $geometry['visual_metrics'] : [];
        $result = [];

        foreach ($metrics as $key => $value) {
            if (is_numeric($value)) {
                $result[(string) $key] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $geometry
     * @return array<int, string>
     */
    private function signals(array $geometry): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $signal): string => trim((string) $signal),
            is_array($geometry['signals'] ?? null) ? $geometry['signals'] : []
        ))));
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private function pageRole(array $geometry): string
    {
        $role = (string) ($geometry['page_role'] ?? 'empty');

        return in_array($role, ['plan', 'specification', 'title', 'detail', 'section', 'empty', 'geometry_only'], true)
            ? $role
            : 'geometry_only';
    }

    /**
     * @param array<string, int|float> $metrics
     * @param array<int, string> $signals
     * @return array<int, string>
     */
    private function reviewReasons(OcrPageResult $page, string $role, array $metrics, array $signals): array
    {
        $reasons = [];
        $hasGeometry = (int) ($metrics['line_count'] ?? 0) > 0
            || (int) ($metrics['curve_count'] ?? 0) > 0
            || (int) ($metrics['rect_count'] ?? 0) > 0
            || (int) ($metrics['vector_element_count'] ?? 0) > 0;

        if ($hasGeometry) {
            $reasons[] = 'geometry_requires_review';
        }

        if ($hasGeometry && trim($page->text) === '') {
            $reasons[] = 'text_layer_empty_with_geometry';
        }

        if ($hasGeometry && in_array($role, ['geometry_only', 'plan', 'detail', 'section'], true)) {
            $reasons[] = 'geometry_without_linked_dimensions';
        }

        if ($hasGeometry && ! in_array('scale_detected', $signals, true)) {
            $reasons[] = 'geometry_without_scale';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, int|float> $metrics
     * @param array<int, string> $signals
     * @param array<int, string> $reviewReasons
     * @return array<int, array<string, mixed>>
     */
    private function metricElements(
        int $documentId,
        string $filename,
        OcrPageResult $page,
        array $geometry,
        array $metrics,
        string $role,
        array $signals,
        array $reviewReasons
    ): array {
        $lineCount = (float) ($metrics['line_count'] ?? 0);
        $vectorCount = (float) ($metrics['vector_element_count'] ?? $lineCount);

        if ($lineCount <= 0 && $vectorCount <= 0 && (float) ($metrics['rect_count'] ?? 0) <= 0) {
            return [];
        }

        return [[
            'type' => 'geometry_metric',
            'label' => 'pdf_geometry',
            'value_text' => 'vector_geometry',
            'value_number' => $lineCount,
            'unit' => null,
            'bbox' => null,
            'geometry' => null,
            'confidence' => 0.72,
            'source_ref' => $this->sourceRef($documentId, $filename, $page, 'geometry_metrics'),
            'normalized_payload' => [
                'page_role' => $role,
                'signals' => $signals,
                'visual_metrics' => $metrics,
                'review_required' => $reviewReasons !== [],
                'review_reasons' => $reviewReasons,
                'preview' => is_array($geometry['preview'] ?? null) ? $geometry['preview'] : [],
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    /**
     * @param array<string, mixed> $geometry
     * @param array<string, int|float> $metrics
     * @return array<int, array<string, mixed>>
     */
    private function candidateElements(
        int $documentId,
        string $filename,
        OcrPageResult $page,
        array $geometry,
        array $metrics,
        string $role
    ): array {
        $elements = [];
        $vectors = array_values(array_filter(
            is_array($geometry['vector_elements'] ?? null) ? $geometry['vector_elements'] : [],
            'is_array'
        ));

        if ((int) ($metrics['table_candidate_count'] ?? 0) > 0) {
            $elements[] = $this->candidateElement($documentId, $filename, $page, 'table', 'table_candidate', $this->dominantBbox($vectors), [
                'candidate_count' => (int) ($metrics['table_candidate_count'] ?? 0),
                'source' => 'pdf_geometry',
                'page_role' => $role,
            ]);
        }

        if ((int) ($metrics['title_block_candidate_count'] ?? 0) > 0) {
            $elements[] = $this->candidateElement($documentId, $filename, $page, 'title_block', 'title_block_candidate', $this->dominantBbox($vectors), [
                'candidate_count' => (int) ($metrics['title_block_candidate_count'] ?? 0),
                'source' => 'pdf_geometry',
                'page_role' => $role,
            ]);
        }

        foreach (array_slice($vectors, 0, 20) as $index => $vector) {
            $bbox = $this->normalizedBbox($vector['bbox'] ?? null);

            if ($bbox === null) {
                continue;
            }

            $kind = (string) ($vector['kind'] ?? 'path');
            $type = $kind === 'rect' ? 'contour' : ($this->looksLikeDimensionLine($bbox) ? 'dimension' : 'geometry_path');

            $elements[] = $this->candidateElement($documentId, $filename, $page, $type, $kind, $bbox, [
                'source' => 'pdf_geometry',
                'vector_index' => $index,
                'vector_kind' => $kind,
                'geometry' => is_array($vector['geometry'] ?? null) ? $vector['geometry'] : [],
                'page_role' => $role,
            ]);
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $bbox
     */
    private function looksLikeDimensionLine(array $bbox): bool
    {
        $width = (float) ($bbox['width'] ?? 0.0);
        $height = (float) ($bbox['height'] ?? 0.0);

        return ($width >= 40.0 && $height <= 3.0) || ($height >= 40.0 && $width <= 3.0);
    }

    /**
     * @param array<int, array<string, mixed>> $vectors
     * @return array<string, float>|null
     */
    private function dominantBbox(array $vectors): ?array
    {
        $boxes = [];

        foreach ($vectors as $vector) {
            $bbox = $this->normalizedBbox($vector['bbox'] ?? null);

            if ($bbox !== null) {
                $boxes[] = $bbox;
            }
        }

        if ($boxes === []) {
            return null;
        }

        $left = min(array_column($boxes, 'x'));
        $top = min(array_column($boxes, 'y'));
        $right = max(array_map(static fn (array $bbox): float => $bbox['x'] + $bbox['width'], $boxes));
        $bottom = max(array_map(static fn (array $bbox): float => $bbox['y'] + $bbox['height'], $boxes));

        return [
            'x' => round($left, 4),
            'y' => round($top, 4),
            'width' => round($right - $left, 4),
            'height' => round($bottom - $top, 4),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, float>|null $bbox
     * @return array<string, mixed>
     */
    private function candidateElement(
        int $documentId,
        string $filename,
        OcrPageResult $page,
        string $type,
        string $label,
        ?array $bbox,
        array $payload
    ): array {
        return [
            'type' => $type,
            'label' => $label,
            'value_text' => null,
            'value_number' => null,
            'unit' => null,
            'bbox' => $bbox,
            'geometry' => is_array($payload['geometry'] ?? null) ? $payload['geometry'] : null,
            'confidence' => 0.62,
            'source_ref' => [
                ...$this->sourceRef($documentId, $filename, $page, $label),
                'evidence_kind' => 'pdf_geometry',
                'bbox' => $bbox,
            ],
            'normalized_payload' => $payload,
            'page_number' => $page->pageNumber,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceRef(int $documentId, string $filename, OcrPageResult $page, string $excerpt): array
    {
        return [
            'type' => 'drawing_geometry',
            'document_id' => $documentId,
            'filename' => $filename,
            'page_number' => $page->pageNumber,
            'excerpt' => $excerpt,
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizedBbox(mixed $bbox): ?array
    {
        if (! is_array($bbox)) {
            return null;
        }

        if (is_numeric($bbox['x'] ?? null) && is_numeric($bbox['y'] ?? null)) {
            return [
                'x' => (float) $bbox['x'],
                'y' => (float) $bbox['y'],
                'width' => (float) ($bbox['width'] ?? 0.0),
                'height' => (float) ($bbox['height'] ?? 0.0),
            ];
        }

        if (array_is_list($bbox) && count($bbox) === 4) {
            return [
                'x' => (float) $bbox[0],
                'y' => (float) $bbox[1],
                'width' => max((float) $bbox[2] - (float) $bbox[0], 0.0),
                'height' => max((float) $bbox[3] - (float) $bbox[1], 0.0),
            ];
        }

        return null;
    }
}
