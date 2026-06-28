<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

final class RuleBasedDrawingAnalysisProvider implements DrawingAnalysisProviderInterface
{
    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData
    {
        $elements = [];
        $takeoffs = [];

        foreach ($recognition->pages as $page) {
            foreach ($this->lines($page) as $line) {
                array_push(
                    $elements,
                    ...$this->scaleElements($documentId, $filename, $page, $line),
                    ...$this->roomElements($documentId, $filename, $page, $line),
                    ...$this->openingElements($documentId, $filename, $page, $line),
                    ...$this->engineeringRouteElements($documentId, $filename, $page, $line),
                    ...$this->dimensionElements($documentId, $filename, $page, $line),
                );

                array_push(
                    $takeoffs,
                    ...$this->roomTakeoffs($documentId, $filename, $page, $line),
                    ...$this->openingTakeoffs($documentId, $filename, $page, $line),
                    ...$this->engineeringRouteTakeoffs($documentId, $filename, $page, $line),
                );
            }
        }

        $roomTakeoffs = array_values(array_filter(
            $takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
        ));
        array_push($takeoffs, ...$this->aggregateRoomTakeoffs($roomTakeoffs));

        $pageProfiles = $this->pageProfiles($filename, $recognition->pages, $elements, $takeoffs);
        $summary = $this->summary($filename, $recognition, $elements, $takeoffs, $roomTakeoffs, $pageProfiles);

        return new DrawingAnalysisResultData(
            elements: $this->unique($elements, ['type', 'label', 'value_text', 'page_number']),
            takeoffs: $this->unique($takeoffs, ['scope_key', 'name', 'unit', 'quantity', 'page_number']),
            summary: $summary,
        );
    }

    private function scaleElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?:масштаб|м)\s*[: ]?\s*1\s*:\s*(?<scale>\d+)/iu', $line, $match) !== 1) {
            return [];
        }

        return [[
            'type' => 'scale',
            'label' => 'Масштаб',
            'value_text' => '1:' . $match['scale'],
            'value_number' => (float) $match['scale'],
            'unit' => null,
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => ['scale_denominator' => (int) $match['scale']],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function roomElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $elements = [];

        foreach ($this->roomAreaMatches($line) as $index => $match) {
            $elements[] = [
                'type' => 'room',
                'label' => $match['label'] !== '' ? $match['label'] : 'Помещение ' . ($index + 1),
                'value_text' => $this->formatNumber($match['area']) . ' м2',
                'value_number' => $match['area'],
                'unit' => 'м2',
                'bbox' => null,
                'geometry' => null,
                'confidence' => $this->confidence($page),
                'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
                'normalized_payload' => [
                    'line' => $line,
                    'quantity_key' => 'finish.floor',
                    'room_label' => $match['label'],
                ],
                'page_number' => $page->pageNumber,
            ];
        }

        return $elements;
    }

    private function openingElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?<label>(?:окно|дверь|ворота|ок|дп|ду)[\w\- ]*)\s+(?<width>\d{2,5})\s*[xх×]\s*(?<height>\d{2,5})(?:\s*[-–]\s*(?<count>\d+)\s*шт)?/iu', $line, $match) !== 1) {
            return [];
        }

        $label = trim($match['label']);

        return [[
            'type' => 'opening',
            'label' => $label,
            'value_text' => $match['width'] . 'x' . $match['height'],
            'value_number' => (float) ($match['count'] ?? 1),
            'unit' => 'шт',
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'quantity_key' => $this->openingQuantityKey($label),
                'width_mm' => (int) $match['width'],
                'height_mm' => (int) $match['height'],
                'count' => (int) ($match['count'] ?? 1),
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function engineeringRouteElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?<label>вентиляция|водоснабжение|канализация|кабель|трасса|воздуховод)[^\\n]*(?:l|длина)\s*=\s*(?<length>\d+(?:[,.]\d+)?)\s*м/iu', $line, $match) !== 1) {
            return [];
        }

        return [[
            'type' => 'engineering_route',
            'label' => trim($match['label']),
            'value_text' => $this->formatNumber($this->number($match['length'])) . ' м',
            'value_number' => $this->number($match['length']),
            'unit' => 'м',
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => ['line' => $line],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function dimensionElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?<length>\d+(?:[,.]\d+)?)\s*[xх×]\s*(?<width>\d+(?:[,.]\d+)?)/iu', $line, $match) !== 1) {
            return [];
        }

        $length = $this->number($match['length']);
        $width = $this->number($match['width']);

        return [[
            'type' => 'dimension',
            'label' => 'Размер',
            'value_text' => $match['length'] . 'x' . $match['width'],
            'value_number' => null,
            'unit' => null,
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'length' => $length,
                'width' => $width,
                'length_mm' => $length,
                'width_mm' => $width,
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function roomTakeoffs(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $takeoffs = [];

        foreach ($this->roomElements($documentId, $filename, $page, $line) as $element) {
            $takeoffs[] = [
                'source_element_ids' => [],
                'scope_key' => 'room_area',
                'work_intent' => ['scope' => 'finishing', 'basis' => 'room_area'],
                'name' => $element['label'],
                'unit' => 'м2',
                'quantity' => $element['value_number'],
                'formula' => $element['value_text'],
                'confidence' => $element['confidence'],
                'source_refs' => [$element['source_ref']],
                'normalized_payload' => [
                    'line' => $line,
                    'room_label' => $element['label'],
                ],
                'page_number' => $page->pageNumber,
            ];
        }

        return $takeoffs;
    }

    private function openingTakeoffs(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $elements = $this->openingElements($documentId, $filename, $page, $line);

        if ($elements === []) {
            return [];
        }

        $element = $elements[0];

        return [[
            'source_element_ids' => [],
            'scope_key' => 'opening_count',
            'work_intent' => ['scope' => 'openings', 'basis' => 'opening_marker'],
            'name' => $element['label'],
            'unit' => 'шт',
            'quantity' => $element['value_number'],
            'formula' => $element['value_text'],
            'confidence' => $element['confidence'],
            'source_refs' => [$element['source_ref']],
            'normalized_payload' => $element['normalized_payload'],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function engineeringRouteTakeoffs(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $elements = $this->engineeringRouteElements($documentId, $filename, $page, $line);

        if ($elements === []) {
            return [];
        }

        $element = $elements[0];

        return [[
            'source_element_ids' => [],
            'scope_key' => 'engineering_route_length',
            'work_intent' => ['scope' => 'engineering', 'basis' => 'route_length'],
            'name' => $element['label'],
            'unit' => 'м',
            'quantity' => $element['value_number'],
            'formula' => $element['value_text'],
            'confidence' => $element['confidence'],
            'source_refs' => [$element['source_ref']],
            'normalized_payload' => ['line' => $line],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function sourceRef(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        return [
            'type' => 'drawing',
            'document_id' => $documentId,
            'filename' => $filename,
            'page_number' => $page->pageNumber,
            'excerpt' => mb_substr($line, 0, 240),
        ];
    }

    private function confidence(OcrPageResult $page): float
    {
        return min(max($page->confidence ?? 0.7, 0.0), 1.0);
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function lines(OcrPageResult $page): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $page->text) ?: []
        )));
    }

    private function unique(array $items, array $keys): array
    {
        $unique = [];

        foreach ($items as $item) {
            $key = implode('|', array_map(static fn (string $field): string => (string) ($item[$field] ?? ''), $keys));
            $unique[$key] = array_diff_key($item, ['page_number' => true]);
        }

        return array_values($unique);
    }

    /**
     * @return array<int, array{label: string, area: float}>
     */
    private function roomAreaMatches(string $line): array
    {
        if (preg_match_all('/(?:(?<label>[\p{L}\d][\p{L}\d\s№#.\-]{0,80}?)\s*(?:s|площадь)?\s*[=:]?\s*)?(?<area>\d{1,4}(?:[,.]\d{1,2})?)\s*(?:м2|м²|m2|m²)\b/iu', $line, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $areas = [];

        foreach ($matches as $match) {
            $area = $this->number((string) $match['area']);

            if ($area < 1 || $area > 500) {
                continue;
            }

            $label = $this->roomLabel((string) ($match['label'] ?? ''));
            $normalizedLine = mb_strtolower($line);

            if (str_contains($normalizedLine, 'масштаб') || str_contains($normalizedLine, 'период цен')) {
                continue;
            }

            $areas[] = [
                'label' => $label,
                'area' => round($area, 4),
            ];
        }

        return $areas;
    }

    private function roomLabel(string $value): string
    {
        $label = trim($value);
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = trim((string) preg_replace('/(?:^|\s)(?:s|площадь)\s*$/iu', '', $label));
        $label = trim($label, ":-–— \t\n\r\0\x0B");

        if ($label === '' || preg_match('/^\d+(?:[,.]\d+)?$/u', $label) === 1) {
            return '';
        }

        return mb_substr($label, 0, 80);
    }

    /**
     * @param array<int, array<string, mixed>> $roomTakeoffs
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRoomTakeoffs(array $roomTakeoffs): array
    {
        if ($roomTakeoffs === []) {
            return [];
        }

        $totalArea = 0.0;
        $sourceRefs = [];
        $confidence = 0.0;

        foreach ($roomTakeoffs as $takeoff) {
            $totalArea += (float) ($takeoff['quantity'] ?? 0);
            $confidence += (float) ($takeoff['confidence'] ?? 0.72);

            foreach (($takeoff['source_refs'] ?? []) as $sourceRef) {
                if (is_array($sourceRef)) {
                    $sourceRefs[] = $sourceRef;
                }
            }
        }

        $roomCount = count($roomTakeoffs);
        $totalArea = round($totalArea, 2);
        $confidence = round($confidence / max($roomCount, 1), 4);
        $sourceRefs = array_slice($sourceRefs, 0, 50);

        return [
            $this->aggregateTakeoff(
                scopeKey: 'floor_finish_area',
                quantityKey: 'finish.floor',
                name: 'Площадь чистовой отделки пола по планировке',
                quantity: $totalArea,
                formula: 'Сумма площадей помещений: ' . $this->formatNumber($totalArea) . ' м2',
                confidence: min($confidence + 0.03, 0.98),
                sourceRefs: $sourceRefs,
                roomCount: $roomCount,
                reviewRequired: false
            ),
            $this->aggregateTakeoff(
                scopeKey: 'rough_floor_area',
                quantityKey: 'rough.floor',
                name: 'Площадь основания пола по планировке',
                quantity: $totalArea,
                formula: 'Сумма площадей помещений: ' . $this->formatNumber($totalArea) . ' м2',
                confidence: min($confidence + 0.02, 0.98),
                sourceRefs: $sourceRefs,
                roomCount: $roomCount,
                reviewRequired: false
            ),
            $this->aggregateTakeoff(
                scopeKey: 'ceiling_finish_area',
                quantityKey: 'office.ceiling',
                name: 'Площадь потолков по планировке',
                quantity: $totalArea,
                formula: 'Принято по площади помещений: ' . $this->formatNumber($totalArea) . ' м2',
                confidence: max($confidence - 0.04, 0.35),
                sourceRefs: $sourceRefs,
                roomCount: $roomCount,
                reviewRequired: false
            ),
            $this->aggregateTakeoff(
                scopeKey: 'wall_finish_area',
                quantityKey: 'rough.walls',
                name: 'Расчетная площадь стен по планировке',
                quantity: round($totalArea * 2.7, 2),
                formula: 'Площадь стен ориентировочно: ' . $this->formatNumber($totalArea) . ' м2 x 2.7',
                confidence: max($confidence - 0.22, 0.35),
                sourceRefs: $sourceRefs,
                roomCount: $roomCount,
                reviewRequired: true
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRefs
     * @return array<string, mixed>
     */
    private function aggregateTakeoff(
        string $scopeKey,
        string $quantityKey,
        string $name,
        float $quantity,
        string $formula,
        float $confidence,
        array $sourceRefs,
        int $roomCount,
        bool $reviewRequired
    ): array {
        return [
            'source_element_ids' => [],
            'scope_key' => $scopeKey,
            'work_intent' => ['scope' => 'finishing', 'basis' => 'room_area_sum'],
            'name' => $name,
            'unit' => 'м2',
            'quantity' => $quantity,
            'formula' => $formula,
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'source_refs' => $sourceRefs,
            'normalized_payload' => [
                'quantity_key' => $quantityKey,
                'room_count' => $roomCount,
                'calculation_basis' => 'room_area_sum',
                'review_required' => $reviewRequired,
            ],
            'page_number' => 0,
        ];
    }

    /**
     * @param array<int, OcrPageResult> $pages
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $takeoffs
     * @return array<int, array<string, mixed>>
     */
    private function pageProfiles(string $filename, array $pages, array $elements, array $takeoffs): array
    {
        $profiles = [];

        foreach ($pages as $page) {
            $pageNumber = $page->pageNumber;
            $pageElements = array_values(array_filter(
                $elements,
                static fn (array $element): bool => (int) ($element['page_number'] ?? 0) === $pageNumber
            ));
            $pageTakeoffs = array_values(array_filter(
                $takeoffs,
                static fn (array $takeoff): bool => (int) ($takeoff['page_number'] ?? 0) === $pageNumber
            ));
            $roomCount = count(array_filter(
                $pageTakeoffs,
                static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
            ));
            $dimensionCount = count(array_filter(
                $pageElements,
                static fn (array $element): bool => ($element['type'] ?? null) === 'dimension'
            ));
            $text = mb_strtolower($page->text . ' ' . $filename);
            $hasPlanSignal = preg_match('/план|планировка|экспликац/ui', $text) === 1;
            $hasSpecificationSignal = preg_match('/спецификац|ведомость|экспликац/ui', $text) === 1;
            $hasEstimateSignal = preg_match('/(?:гэсн|фер|тер)?\s*\d{2}-\d{2}-\d{3}-\d{2,3}|смет/ui', $text) === 1;
            $role = $this->pageRole($hasPlanSignal, $hasSpecificationSignal, $hasEstimateSignal, $roomCount, $dimensionCount);

            $profiles[] = [
                'page_number' => $pageNumber,
                'page_role' => $role,
                'confidence' => $this->pageRoleConfidence($page, $role, $hasPlanSignal, $roomCount, $dimensionCount),
                'signals' => array_values(array_filter([
                    $hasPlanSignal ? 'plan_keywords' : null,
                    $hasSpecificationSignal ? 'specification_keywords' : null,
                    $hasEstimateSignal ? 'estimate_or_norm_keywords' : null,
                    $roomCount > 0 ? 'room_areas' : null,
                    $dimensionCount > 0 ? 'dimensions' : null,
                ])),
                'room_area_count' => $roomCount,
                'dimension_count' => $dimensionCount,
            ];
        }

        return $profiles;
    }

    private function pageRole(
        bool $hasPlanSignal,
        bool $hasSpecificationSignal,
        bool $hasEstimateSignal,
        int $roomCount,
        int $dimensionCount
    ): string {
        if (($hasPlanSignal && ($roomCount > 0 || $dimensionCount > 0)) || $roomCount >= 2) {
            return 'floor_plan';
        }

        if ($hasSpecificationSignal) {
            return 'specification';
        }

        if ($hasEstimateSignal) {
            return 'reference_estimate';
        }

        return 'technical_document';
    }

    private function pageRoleConfidence(
        OcrPageResult $page,
        string $role,
        bool $hasPlanSignal,
        int $roomCount,
        int $dimensionCount
    ): float {
        $confidence = min(max($page->confidence ?? 0.7, 0.35), 0.95);

        if ($role === 'floor_plan') {
            $confidence += $hasPlanSignal ? 0.05 : 0.0;
            $confidence += min($roomCount * 0.02, 0.12);
            $confidence += min($dimensionCount * 0.015, 0.08);
        }

        return round(min($confidence, 0.98), 4);
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, array<string, mixed>> $takeoffs
     * @param array<int, array<string, mixed>> $roomTakeoffs
     * @param array<int, array<string, mixed>> $pageProfiles
     * @return array<string, mixed>
     */
    private function summary(
        string $filename,
        OcrRecognitionResult $recognition,
        array $elements,
        array $takeoffs,
        array $roomTakeoffs,
        array $pageProfiles
    ): array {
        $roomAreaTotal = round(array_reduce(
            $roomTakeoffs,
            static fn (float $carry, array $takeoff): float => $carry + (float) ($takeoff['quantity'] ?? 0),
            0.0
        ), 2);
        $dimensionCount = count(array_filter(
            $elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'dimension'
        ));
        $documentProfile = $this->documentProfile($filename, $pageProfiles, count($roomTakeoffs), $dimensionCount);

        return [
            'pages_count' => count($recognition->pages),
            'elements_count' => count($elements),
            'takeoffs_count' => count($takeoffs),
            'document_profile' => $documentProfile,
            'page_profiles' => $pageProfiles,
            'source_format' => $this->sourceFormat($filename),
            'room_count' => count($roomTakeoffs),
            'room_area_total_m2' => $roomAreaTotal > 0 ? $roomAreaTotal : null,
            'dimension_count' => $dimensionCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pageProfiles
     * @return array<string, mixed>
     */
    private function documentProfile(string $filename, array $pageProfiles, int $roomCount, int $dimensionCount): array
    {
        $roles = array_count_values(array_map(
            static fn (array $profile): string => (string) ($profile['page_role'] ?? 'technical_document'),
            $pageProfiles
        ));
        arsort($roles);

        $role = array_key_first($roles) ?? 'technical_document';
        $confidence = $pageProfiles !== []
            ? array_sum(array_map(static fn (array $profile): float => (float) ($profile['confidence'] ?? 0.5), $pageProfiles)) / count($pageProfiles)
            : 0.5;

        if ($role === 'technical_document' && ($roomCount >= 2 || $dimensionCount >= 2)) {
            $role = 'floor_plan';
            $confidence = max($confidence, 0.72);
        }

        return [
            'document_role' => $role,
            'source_format' => $this->sourceFormat($filename),
            'confidence' => round(min(max($confidence, 0.35), 0.98), 4),
            'requires_manual_review' => $role === 'floor_plan' && $roomCount === 0,
            'signals' => [
                'room_count' => $roomCount,
                'dimension_count' => $dimensionCount,
            ],
        ];
    }

    private function sourceFormat(string $filename): string
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'pdf',
            'dwg', 'dxf', 'ifc', 'rvt' => 'cad',
            'png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff', 'bmp' => 'image',
            'xls', 'xlsx', 'csv' => 'spreadsheet',
            'doc', 'docx', 'rtf' => 'text_document',
            default => 'unknown',
        };
    }

    private function openingQuantityKey(string $label): string
    {
        $normalized = mb_strtolower($label);

        return match (true) {
            str_contains($normalized, 'окно') || str_contains($normalized, 'ок-') => 'openings.windows',
            str_contains($normalized, 'ворота') => 'openings.gates',
            default => 'openings.doors',
        };
    }
}
