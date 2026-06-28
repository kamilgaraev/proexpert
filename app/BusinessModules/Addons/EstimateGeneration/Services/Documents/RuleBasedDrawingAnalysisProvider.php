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

        return new DrawingAnalysisResultData(
            elements: $this->unique($elements, ['type', 'label', 'value_text', 'page_number']),
            takeoffs: $this->unique($takeoffs, ['scope_key', 'name', 'unit', 'quantity', 'page_number']),
            summary: [
                'pages_count' => count($recognition->pages),
                'elements_count' => count($elements),
                'takeoffs_count' => count($takeoffs),
            ],
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
        if (preg_match('/(?<label>(?:помещение|кабинет|комната|санузел|коридор)[^=]{0,80})\s*(?:s|площадь)?\s*=\s*(?<area>\d+(?:[,.]\d+)?)\s*(?:м2|м²)/iu', $line, $match) !== 1) {
            return [];
        }

        return [[
            'type' => 'room',
            'label' => trim($match['label']),
            'value_text' => str_replace(',', '.', $match['area']) . ' м2',
            'value_number' => $this->number($match['area']),
            'unit' => 'м2',
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => ['line' => $line],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function openingElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?<label>(?:окно|дверь|ворота|ок|дп|ду)[\w\- ]*)\s+(?<width>\d{2,5})\s*[xх×]\s*(?<height>\d{2,5})(?:\s*[-–]\s*(?<count>\d+)\s*шт)?/iu', $line, $match) !== 1) {
            return [];
        }

        return [[
            'type' => 'opening',
            'label' => trim($match['label']),
            'value_text' => $match['width'] . 'x' . $match['height'],
            'value_number' => (float) ($match['count'] ?? 1),
            'unit' => 'шт',
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
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
            'value_text' => str_replace(',', '.', $match['length']) . ' м',
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
                'length' => $this->number($match['length']),
                'width' => $this->number($match['width']),
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function roomTakeoffs(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $elements = $this->roomElements($documentId, $filename, $page, $line);

        if ($elements === []) {
            return [];
        }

        $element = $elements[0];

        return [[
            'source_element_ids' => [],
            'scope_key' => 'room_area',
            'work_intent' => ['scope' => 'finishing', 'basis' => 'room_area'],
            'name' => $element['label'],
            'unit' => 'м2',
            'quantity' => $element['value_number'],
            'formula' => $element['value_text'],
            'confidence' => $element['confidence'],
            'source_refs' => [$element['source_ref']],
            'normalized_payload' => ['line' => $line],
            'page_number' => $page->pageNumber,
        ]];
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
}
