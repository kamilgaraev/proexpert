<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

final class RuleBasedDrawingAnalysisProvider implements DrawingAnalysisProviderInterface
{
    public function __construct(
        private readonly QuantityStatementLineParser $quantityLineParser = new QuantityStatementLineParser(),
    ) {}

    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData
    {
        $elements = [];
        $takeoffs = [];

        foreach ($recognition->pages as $page) {
            foreach ($this->lineRecords($page) as $lineRecord) {
                $line = $lineRecord['text'];
                $lineElements = [
                    ...$this->scaleElements($documentId, $filename, $page, $line),
                    ...$this->heightElements($documentId, $filename, $page, $line),
                    ...$this->roomElements($documentId, $filename, $page, $line),
                    ...$this->openingElements($documentId, $filename, $page, $line),
                    ...$this->engineeringRouteElements($documentId, $filename, $page, $line),
                    ...$this->specificationElements($documentId, $filename, $page, $line),
                    ...$this->dimensionElements($documentId, $filename, $page, $line),
                ];
                array_push($elements, ...$this->withElementLineEvidence($lineElements, $lineRecord));

                $lineTakeoffs = [
                    ...$this->roomTakeoffs($documentId, $filename, $page, $line),
                    ...$this->openingTakeoffs($documentId, $filename, $page, $line),
                    ...$this->engineeringRouteTakeoffs($documentId, $filename, $page, $line),
                    ...$this->specificationTakeoffs($documentId, $filename, $page, $line),
                ];
                array_push($takeoffs, ...$this->withTakeoffLineEvidence($lineTakeoffs, $lineRecord));
            }
        }

        $roomTakeoffs = array_values(array_filter(
            $takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
        ));
        array_push($takeoffs, ...$this->aggregateRoomTakeoffs($roomTakeoffs, $elements));

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

    private function heightElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $height = $this->heightMetersFromLine($line);

        if ($height === null) {
            return [];
        }

        return [[
            'type' => 'height',
            'label' => 'Высота помещения',
            'value_text' => $this->formatNumber($height) . ' м',
            'value_number' => $height,
            'unit' => 'м',
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'line' => $line,
                'height_m' => $height,
            ],
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

    private function specificationElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $item = $this->quantityLine($filename, $line);

        if ($item === null) {
            return [];
        }

        if (($item['mapped'] ?? false) !== true) {
            return [[
                'type' => 'unmapped_specification_row',
                'label' => $item['name'],
                'value_text' => $this->formatNumber((float) $item['quantity']) . ' ' . $item['unit'],
                'value_number' => $item['quantity'],
                'unit' => $item['unit'],
                'bbox' => null,
                'geometry' => null,
                'confidence' => max($this->confidence($page) - 0.15, 0.35),
                'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
                'normalized_payload' => [
                    'line' => $line,
                    'source' => $item['source'],
                    'review_required' => true,
                    'reason' => 'quantity_row_not_mapped',
                ],
                'page_number' => $page->pageNumber,
            ]];
        }

        return [[
            'type' => 'specification_item',
            'label' => $item['name'],
            'value_text' => $this->formatNumber($item['quantity']) . ' ' . $item['unit'],
            'value_number' => $item['quantity'],
            'unit' => $item['unit'],
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'line' => $line,
                'quantity_key' => $item['quantity_key'],
                'scope_type' => $item['scope_type'],
                'source' => $item['source'],
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function dimensionElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?<length>\d+(?:[,.]\d+)?)\s*[xх×]\s*(?<width>\d+(?:[,.]\d+)?)/iu', $line, $match) === 1) {
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
                    'dimension_kind' => 'pair',
                    'length' => $length,
                    'width' => $width,
                    'length_mm' => $length,
                    'width_mm' => $width,
                ],
                'page_number' => $page->pageNumber,
            ]];
        }

        $linearDimension = $this->linearDimensionFromLine($line);

        if ($linearDimension === null) {
            return [];
        }

        return [[
            'type' => 'dimension',
            'label' => 'Размер',
            'value_text' => $linearDimension['value_text'],
            'value_number' => $linearDimension['value_mm'],
            'unit' => 'мм',
            'bbox' => null,
            'geometry' => null,
            'confidence' => max($this->confidence($page) - 0.08, 0.35),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'dimension_kind' => 'linear',
                'line' => $line,
                'value_mm' => $linearDimension['value_mm'],
                'value_m' => $linearDimension['value_m'],
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    /**
     * @return array{value_text: string, value_mm: float, value_m: float}|null
     */
    private function linearDimensionFromLine(string $line): ?array
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));

        if ($line === '' || $this->looksLikeNonDimensionNumericLine($line)) {
            return null;
        }

        if (preg_match('/^(?<value>\d{3,5}|\d{1,2}[\s\x{00A0}]\d{3})(?:\s*(?<unit>mm|m|мм|м))?$/iu', $line, $match) !== 1) {
            return null;
        }

        $rawValue = str_replace(["\xC2\xA0", ' '], '', (string) $match['value']);
        $value = $this->number($rawValue);
        $unit = mb_strtolower((string) ($match['unit'] ?? ''));
        $valueM = ($unit === 'm' || $unit === 'м') && $value <= 50
            ? $value
            : $value / 1000;

        if ($valueM < 0.5 || $valueM > 50) {
            return null;
        }

        return [
            'value_text' => $rawValue,
            'value_mm' => round($valueM * 1000, 3),
            'value_m' => round($valueM, 3),
        ];
    }

    private function looksLikeNonDimensionNumericLine(string $line): bool
    {
        $normalized = mb_strtolower($line);

        if (preg_match('/(?:\d{2}-\d{2}-\d{3}|[,.]\d{1,2}\s*(?:м2|м²|m2|m²)|%|₽|rub|руб)/iu', $normalized) === 1) {
            return true;
        }

        return $this->containsAny($normalized, [
            'масштаб',
            'смет',
            'цен',
            'итог',
        ]);
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

    private function specificationTakeoffs(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $item = $this->quantityLine($filename, $line);

        if ($item === null || ($item['mapped'] ?? false) !== true) {
            return [];
        }

        return [[
            'source_element_ids' => [],
            'scope_key' => 'specification_quantity',
            'work_intent' => [
                'scope' => $item['scope_type'],
                'basis' => $item['source'] === 'work_volume_statement' ? 'work_volume_statement_row' : 'specification_row',
            ],
            'name' => $item['name'],
            'unit' => $item['unit'],
            'quantity' => $item['quantity'],
            'formula' => $this->formatNumber($item['quantity']) . ' ' . $item['unit'],
            'confidence' => $this->confidence($page),
            'source_refs' => [$this->sourceRef($documentId, $filename, $page, $line)],
            'normalized_payload' => [
                'line' => $line,
                'quantity_key' => $item['quantity_key'],
                'scope_type' => $item['scope_type'],
                'source' => $item['source'],
                'unit' => $item['unit'],
                'review_required' => (bool) ($item['review_required'] ?? false),
            ],
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

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function heightMetersFromLine(string $line): ?float
    {
        if ($this->isOpeningScheduleHeightLine($line)) {
            return null;
        }

        $patterns = [
            '/(?:^|[^\p{L}\p{N}])h\s*[=:]?\s*(?<height>\d{1,4}(?:[,.]\d{1,2})?)\s*(?<unit>мм|mm|м|m)?(?:$|[^\p{L}\p{N}])/iu',
            '/(?:высота|выс\.)\s*(?:потолк(?:а|ов)?|помещени[яй])?\s*[=:]?\s*(?<height>\d{1,4}(?:[,.]\d{1,2})?)\s*(?<unit>мм|mm|м|m)?/iu',
            '/потолк(?:а|ов)?\s*[=:]?\s*(?<height>\d{1,4}(?:[,.]\d{1,2})?)\s*(?<unit>мм|mm|м|m)?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }

            $height = $this->number((string) $match['height']);
            $unit = mb_strtolower((string) ($match['unit'] ?? ''));

            if ($unit === 'мм' || $unit === 'mm' || $height > 10) {
                $height /= 1000;
            }

            if ($height >= 2.0 && $height <= 6.0) {
                return round($height, 3);
            }
        }

        return null;
    }

    private function isOpeningScheduleHeightLine(string $line): bool
    {
        $normalized = mb_strtolower($line);

        if (!$this->containsAny($normalized, ['двер', 'окн', 'ворот', 'дп-', 'ду-', 'ок-', 'проем', 'проём', 'door', 'window', 'gate'])) {
            return false;
        }

        return preg_match('/(?:^|[^\p{L}\p{N}])h\s*=/iu', $line) === 1
            || preg_match('/(?:^|[^\p{L}\p{N}])(?:b|w)\s*=/iu', $line) === 1
            || preg_match('/\d{2,5}\s*[xх×]\s*\d{2,5}/u', $line) === 1;
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

    /**
     * @return array<int, array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}>
     */
    private function lineRecords(OcrPageResult $page): array
    {
        $records = [];

        foreach ($page->blocks as $blockIndex => $block) {
            if (!is_array($block)) {
                continue;
            }

            $blockBbox = $this->normalizedBbox($block['bounding_box'] ?? $block['bbox'] ?? null);
            $lines = is_array($block['lines'] ?? null) ? $block['lines'] : [];

            foreach ($lines as $lineIndex => $line) {
                if (!is_array($line)) {
                    continue;
                }

                $text = $this->lineText($line);

                if ($text === '') {
                    continue;
                }

                $bbox = $this->normalizedBbox($line['bounding_box'] ?? $line['bbox'] ?? null)
                    ?? $this->wordsBbox(is_array($line['words'] ?? null) ? $line['words'] : [])
                    ?? $blockBbox;

                $records[] = $this->lineRecord(
                    text: $text,
                    bbox: $bbox,
                    pageNumber: $page->pageNumber,
                    blockIndex: (int) $blockIndex,
                    lineIndex: (int) $lineIndex
                );
            }

            if ($lines === []) {
                foreach ($this->splitTextLines((string) ($block['text'] ?? '')) as $lineIndex => $text) {
                    $records[] = $this->lineRecord(
                        text: $text,
                        bbox: $blockBbox,
                        pageNumber: $page->pageNumber,
                        blockIndex: (int) $blockIndex,
                        lineIndex: (int) $lineIndex
                    );
                }
            }
        }

        if ($records !== []) {
            return $records;
        }

        return array_map(
            fn (string $line): array => $this->lineRecord(
                text: $line,
                bbox: null,
                pageNumber: $page->pageNumber,
                blockIndex: null,
                lineIndex: null
            ),
            $this->lines($page)
        );
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineText(array $line): string
    {
        $text = trim((string) ($line['text'] ?? ''));

        if ($text !== '') {
            return $text;
        }

        $words = is_array($line['words'] ?? null) ? $line['words'] : [];

        return trim(implode(' ', array_filter(array_map(
            static fn (mixed $word): string => is_array($word) ? trim((string) ($word['text'] ?? '')) : '',
            $words
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function splitTextLines(string $text): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $text) ?: []
        )));
    }

    /**
     * @param array<string, float>|null $bbox
     * @return array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}
     */
    private function lineRecord(
        string $text,
        ?array $bbox,
        int $pageNumber,
        ?int $blockIndex,
        ?int $lineIndex
    ): array {
        return [
            'text' => $text,
            'bbox' => $bbox,
            'block_index' => $blockIndex,
            'line_index' => $lineIndex,
            'line_hash' => sha1($pageNumber . '|' . ($blockIndex ?? 'text') . '|' . ($lineIndex ?? 'text') . '|' . $text),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string} $lineRecord
     * @return array<int, array<string, mixed>>
     */
    private function withElementLineEvidence(array $items, array $lineRecord): array
    {
        if ($lineRecord['bbox'] === null) {
            return $items;
        }

        foreach ($items as $index => $item) {
            if (($item['bbox'] ?? null) === null) {
                $item['bbox'] = $lineRecord['bbox'];
            }

            $item['source_ref'] = $this->withLineSourceEvidence(
                is_array($item['source_ref'] ?? null) ? $item['source_ref'] : [],
                $lineRecord
            );

            $payload = is_array($item['normalized_payload'] ?? null) ? $item['normalized_payload'] : [];
            $payload['ocr_line_bbox'] = $lineRecord['bbox'];
            $payload['ocr_line_hash'] = $lineRecord['line_hash'];
            $item['normalized_payload'] = $payload;

            $items[$index] = $item;
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $takeoffs
     * @param array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string} $lineRecord
     * @return array<int, array<string, mixed>>
     */
    private function withTakeoffLineEvidence(array $takeoffs, array $lineRecord): array
    {
        if ($lineRecord['bbox'] === null) {
            return $takeoffs;
        }

        foreach ($takeoffs as $index => $takeoff) {
            $sourceRefs = is_array($takeoff['source_refs'] ?? null) ? $takeoff['source_refs'] : [];
            $takeoff['source_refs'] = array_map(
                fn (mixed $sourceRef): array => $this->withLineSourceEvidence(
                    is_array($sourceRef) ? $sourceRef : [],
                    $lineRecord
                ),
                $sourceRefs
            );

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $payload['ocr_line_bbox'] = $lineRecord['bbox'];
            $payload['ocr_line_hash'] = $lineRecord['line_hash'];
            $takeoff['normalized_payload'] = $payload;

            $takeoffs[$index] = $takeoff;
        }

        return $takeoffs;
    }

    /**
     * @param array<string, mixed> $sourceRef
     * @param array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string} $lineRecord
     * @return array<string, mixed>
     */
    private function withLineSourceEvidence(array $sourceRef, array $lineRecord): array
    {
        if ($lineRecord['bbox'] === null) {
            return $sourceRef;
        }

        return [
            ...$sourceRef,
            'evidence_kind' => 'ocr_line',
            'bbox' => $lineRecord['bbox'],
            'block_index' => $lineRecord['block_index'],
            'line_index' => $lineRecord['line_index'],
            'line_hash' => $lineRecord['line_hash'],
        ];
    }

    /**
     * @param array<int, mixed> $words
     * @return array<string, float>|null
     */
    private function wordsBbox(array $words): ?array
    {
        $boxes = [];

        foreach ($words as $word) {
            if (!is_array($word)) {
                continue;
            }

            $bbox = $this->normalizedBbox($word['bounding_box'] ?? $word['bbox'] ?? null);

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
            'x' => $left,
            'y' => $top,
            'width' => round($right - $left, 4),
            'height' => round($bottom - $top, 4),
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizedBbox(mixed $bbox): ?array
    {
        if (!is_array($bbox)) {
            return null;
        }

        if ($this->hasNumericKeys($bbox, ['x', 'y', 'width', 'height'])) {
            return [
                'x' => (float) $bbox['x'],
                'y' => (float) $bbox['y'],
                'width' => (float) $bbox['width'],
                'height' => (float) $bbox['height'],
            ];
        }

        if ($this->hasNumericKeys($bbox, ['left', 'top', 'right', 'bottom'])) {
            return [
                'x' => (float) $bbox['left'],
                'y' => (float) $bbox['top'],
                'width' => round((float) $bbox['right'] - (float) $bbox['left'], 4),
                'height' => round((float) $bbox['bottom'] - (float) $bbox['top'], 4),
            ];
        }

        if (array_is_list($bbox) && count($bbox) === 4 && is_numeric($bbox[0] ?? null) && is_numeric($bbox[1] ?? null)) {
            return [
                'x' => (float) $bbox[0],
                'y' => (float) $bbox[1],
                'width' => (float) $bbox[2],
                'height' => (float) $bbox[3],
            ];
        }

        return $this->bboxFromPoints($bbox['vertices'] ?? $bbox['points'] ?? $bbox['polygon'] ?? $bbox);
    }

    /**
     * @param array<int, string> $keys
     */
    private function hasNumericKeys(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!is_numeric($value[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, float>|null
     */
    private function bboxFromPoints(mixed $points): ?array
    {
        if (!is_array($points)) {
            return null;
        }

        $coordinates = [];

        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $x = $point['x'] ?? $point[0] ?? null;
            $y = $point['y'] ?? $point[1] ?? null;

            if (is_numeric($x) && is_numeric($y)) {
                $coordinates[] = [(float) $x, (float) $y];
            }
        }

        if ($coordinates === []) {
            return null;
        }

        $xs = array_column($coordinates, 0);
        $ys = array_column($coordinates, 1);
        $left = min($xs);
        $top = min($ys);
        $right = max($xs);
        $bottom = max($ys);

        return [
            'x' => $left,
            'y' => $top,
            'width' => round($right - $left, 4),
            'height' => round($bottom - $top, 4),
        ];
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
        if (preg_match_all('/(?<prefix>^|[^\d,.])(?<area>\d{1,4}(?:[,.]\d{1,2})?)\s*(?:м2|м²|m2|m²)\b/iu', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $areas = [];
        $previousMatchEnd = 0;

        foreach ($matches as $match) {
            $areaText = (string) $match['area'][0];
            $areaOffset = (int) $match['area'][1];
            $fullMatchOffset = (int) $match[0][1];
            $fullMatchEnd = $fullMatchOffset + strlen((string) $match[0][0]);
            $rawLabel = $areaOffset > $previousMatchEnd
                ? substr($line, $previousMatchEnd, $areaOffset - $previousMatchEnd)
                : '';
            $area = $this->number($areaText);

            if ($area < 1 || $area > 500) {
                $previousMatchEnd = $fullMatchEnd;
                continue;
            }

            $label = $this->roomLabel($rawLabel);
            $normalizedLine = mb_strtolower($line);

            if (str_contains($normalizedLine, 'масштаб') || str_contains($normalizedLine, 'период цен')) {
                $previousMatchEnd = $fullMatchEnd;
                continue;
            }

            $areas[] = [
                'label' => $label,
                'area' => round($area, 4),
            ];
            $previousMatchEnd = $fullMatchEnd;
        }

        return $areas;
    }

    private function roomLabel(string $value): string
    {
        $label = trim($value);
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = trim((string) preg_replace('/(?:^|\s)(?:s|площадь)\s*[=:]?\s*$/iu', '', $label));
        $label = trim($label, "=:;-–— \t\n\r\0\x0B");

        if ($label === '' || preg_match('/^\d+(?:[,.]\d+)?$/u', $label) === 1) {
            return '';
        }

        return mb_substr($label, 0, 80);
    }

    /**
     * @return array{name: string, unit: string, quantity: float, quantity_key: string|null, scope_type: string, source: string, mapped: bool, review_required: bool}|null
     */
    private function quantityLine(string $filename, string $line): ?array
    {
        return $this->quantityLineParser->parse($line, $this->quantityLineSource($filename, $line));
    }

    private function quantityLineSource(string $filename, string $line): string
    {
        $text = mb_strtolower($filename . ' ' . $line);

        return preg_match('/ведомость\s+(?:объемов|объёмов|работ)|объемы?\s+работ|объёмы?\s+работ|(?:^|[^\p{L}\p{N}])вор(?:$|[^\p{L}\p{N}])/u', $text) === 1
            ? 'work_volume_statement'
            : 'specification';
    }

    /**
     * @param array<int, array<string, mixed>> $roomTakeoffs
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRoomTakeoffs(array $roomTakeoffs, array $elements): array
    {
        if ($roomTakeoffs === []) {
            return [];
        }

        $heightEvidence = $this->heightEvidence($elements);
        $heightM = (float) $heightEvidence['height_m'];
        $dimensionElements = $this->dimensionElementsForRooms($elements);
        $totalArea = 0.0;
        $estimatedWallArea = 0.0;
        $estimatedBaseboardLength = 0.0;
        $wetRoomArea = 0.0;
        $wetRoomWallArea = 0.0;
        $sourceRefs = [];
        $dimensionSourceRefs = [];
        $roomDimensions = [];
        $confidence = 0.0;

        foreach ($roomTakeoffs as $takeoff) {
            $area = (float) ($takeoff['quantity'] ?? 0);
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $roomLabel = (string) ($payload['room_label'] ?? $takeoff['name'] ?? '');

            $totalArea += $area;
            $roomGeometry = $this->roomGeometryFromDimensions($takeoff, $dimensionElements, $area);
            $estimatedRoomPerimeter = $roomGeometry !== null
                ? (float) $roomGeometry['perimeter_m']
                : $this->estimatedPerimeterFromRoomArea($area);
            $estimatedRoomWallArea = $estimatedRoomPerimeter * $heightM;
            $estimatedWallArea += $estimatedRoomWallArea;
            $estimatedBaseboardLength += $estimatedRoomPerimeter;
            $confidence += (float) ($takeoff['confidence'] ?? 0.72);

            if ($roomGeometry !== null) {
                $roomDimensions[] = [
                    'room_label' => $roomLabel,
                    'length_m' => $roomGeometry['length_m'],
                    'width_m' => $roomGeometry['width_m'],
                    'perimeter_m' => $roomGeometry['perimeter_m'],
                    'basis' => $roomGeometry['basis'] ?? 'dimension_geometry',
                ];

                foreach ($roomGeometry['source_refs'] as $sourceRef) {
                    if (is_array($sourceRef)) {
                        $dimensionSourceRefs[] = $sourceRef;
                    }
                }
            }

            if ($this->isWetRoomLabel($roomLabel)) {
                $wetRoomArea += $area;
                $wetRoomWallArea += $estimatedRoomWallArea;
            }

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
        $dimensionSourceRefs = array_slice($dimensionSourceRefs, 0, 50);
        $perimeterSourceRefs = array_slice([
            ...$sourceRefs,
            ...$dimensionSourceRefs,
        ], 0, 50);
        $calculationSourceRefs = array_slice([
            ...$perimeterSourceRefs,
            ...$heightEvidence['source_refs'],
        ], 0, 50);
        $roomDimensionCount = count($roomDimensions);
        $perimeterCalculationBasis = $roomDimensionCount > 0
            ? 'room_dimension_geometry'
            : 'estimated_room_perimeter';

        $baseboardLength = round($estimatedBaseboardLength, 2);
        $wallArea = round($estimatedWallArea > 0 ? $estimatedWallArea : $totalArea * $heightM, 2);
        $heightFormulaPart = ' x ' . $this->formatNumber($heightM) . ' м';
        $aggregates = [
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
                reviewRequired: true
            ),
            $this->aggregateTakeoff(
                scopeKey: 'wall_finish_area',
                quantityKey: 'rough.walls',
                name: 'Расчетная площадь стен по планировке',
                quantity: $wallArea,
                formula: 'Ориентировочная площадь стен по комнатам: сумма 4 x sqrt(S)' . $heightFormulaPart . ' = ' . $this->formatNumber($wallArea) . ' м2',
                confidence: max($confidence - 0.18, 0.35),
                sourceRefs: $calculationSourceRefs,
                roomCount: $roomCount,
                reviewRequired: true,
                extraPayload: [
                    'height_m' => $heightM,
                    'height_source' => $heightEvidence['source'],
                    'calculation_basis' => $perimeterCalculationBasis,
                    'room_dimension_count' => $roomDimensionCount,
                    'room_dimensions' => array_slice($roomDimensions, 0, 20),
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'paint_area',
                quantityKey: 'finish.paint',
                name: 'Расчетная площадь окраски стен по планировке',
                quantity: $wallArea,
                formula: 'Ориентировочная площадь окраски принята по расчетной площади стен: ' . $this->formatNumber($wallArea) . ' м2',
                confidence: max($confidence - 0.22, 0.35),
                sourceRefs: $calculationSourceRefs,
                roomCount: $roomCount,
                reviewRequired: true,
                extraPayload: [
                    'height_m' => $heightM,
                    'height_source' => $heightEvidence['source'],
                    'calculation_basis' => $perimeterCalculationBasis,
                    'room_dimension_count' => $roomDimensionCount,
                    'room_dimensions' => array_slice($roomDimensions, 0, 20),
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'skirting_length',
                quantityKey: 'finish.baseboard',
                name: 'Расчетная длина плинтуса по планировке',
                quantity: $baseboardLength,
                formula: 'Ориентировочная длина плинтуса: сумма 4 x sqrt(S) = ' . $this->formatNumber($baseboardLength) . ' м',
                confidence: max($confidence - 0.24, 0.35),
                sourceRefs: $perimeterSourceRefs,
                roomCount: $roomCount,
                reviewRequired: true,
                unit: 'м',
                extraPayload: [
                    'calculation_basis' => $perimeterCalculationBasis,
                    'room_dimension_count' => $roomDimensionCount,
                    'room_dimensions' => array_slice($roomDimensions, 0, 20),
                    'openings_subtracted' => false,
                ]
            ),
        ];

        if ($wetRoomArea > 0) {
            $wetZoneTileArea = round($wetRoomArea + $wetRoomWallArea, 2);
            $aggregates[] = $this->aggregateTakeoff(
                scopeKey: 'wet_zone_tile_area',
                quantityKey: 'sanitary.tile',
                name: 'Расчетная площадь отделки мокрых зон по планировке',
                quantity: $wetZoneTileArea,
                formula: 'Мокрые зоны: пол ' . $this->formatNumber($wetRoomArea) . ' м2 + стены ' . $this->formatNumber($wetRoomWallArea) . ' м2',
                confidence: max($confidence - 0.2, 0.35),
                sourceRefs: $calculationSourceRefs,
                roomCount: $roomCount,
                reviewRequired: true,
                extraPayload: [
                    'height_m' => $heightM,
                    'height_source' => $heightEvidence['source'],
                    'wet_room_area_m2' => round($wetRoomArea, 2),
                    'wet_room_wall_area_m2' => round($wetRoomWallArea, 2),
                ]
            );
        }

        return $aggregates;
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
        bool $reviewRequired,
        string $unit = 'м2',
        array $extraPayload = []
    ): array {
        return [
            'source_element_ids' => [],
            'scope_key' => $scopeKey,
            'work_intent' => ['scope' => 'finishing', 'basis' => 'room_area_sum'],
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'formula' => $formula,
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'source_refs' => $sourceRefs,
            'normalized_payload' => [
                'quantity_key' => $quantityKey,
                'room_count' => $roomCount,
                'calculation_basis' => 'room_area_sum',
                'review_required' => $reviewRequired,
                ...$extraPayload,
            ],
            'page_number' => 0,
        ];
    }

    private function estimatedPerimeterFromRoomArea(float $area): float
    {
        if ($area <= 0) {
            return 0.0;
        }

        return 4 * sqrt($area);
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @return array<int, array<string, mixed>>
     */
    private function dimensionElementsForRooms(array $elements): array
    {
        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['type'] ?? null) === 'dimension'
                && ($this->dimensionPairMeters($element) !== null || $this->linearDimensionMeters($element) !== null)
                && !$this->isOpeningDimensionElement($element)
        ));
    }

    /**
     * @param array<string, mixed> $roomTakeoff
     * @param array<int, array<string, mixed>> $dimensionElements
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromDimensions(array $roomTakeoff, array $dimensionElements, float $area): ?array
    {
        if ($area <= 0) {
            return null;
        }

        $roomSourceRef = $this->firstSourceRefWithBbox($roomTakeoff['source_refs'] ?? []);

        if ($roomSourceRef === null) {
            return null;
        }

        $roomBbox = $this->normalizedBbox($roomSourceRef['bbox'] ?? null);

        if ($roomBbox === null) {
            return null;
        }

        $roomPage = $this->nullableInt($roomSourceRef['page_number'] ?? null);
        $dimensionPairGeometry = $this->roomGeometryFromDimensionPairs($dimensionElements, $area, $roomBbox, $roomPage);

        if ($dimensionPairGeometry !== null) {
            return $dimensionPairGeometry;
        }

        return $this->roomGeometryFromOrthogonalDimensions($dimensionElements, $area, $roomBbox, $roomPage);
    }

    /**
     * @param array<int, array<string, mixed>> $dimensionElements
     * @param array<string, float> $roomBbox
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromDimensionPairs(array $dimensionElements, float $area, array $roomBbox, ?int $roomPage): ?array
    {
        $best = null;

        foreach ($dimensionElements as $element) {
            $dimension = $this->dimensionPairMeters($element);

            if ($dimension === null || !$this->isDimensionAreaPlausible($area, $dimension['length_m'], $dimension['width_m'])) {
                continue;
            }

            $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
            $dimensionPage = $this->nullableInt($sourceRef['page_number'] ?? $element['page_number'] ?? null);

            if ($roomPage !== null && $dimensionPage !== null && $roomPage !== $dimensionPage) {
                continue;
            }

            $dimensionBbox = $this->normalizedBbox($element['bbox'] ?? $sourceRef['bbox'] ?? null);

            if ($dimensionBbox === null) {
                continue;
            }

            $distance = $this->bboxCenterDistance($roomBbox, $dimensionBbox);
            $limit = $this->dimensionMatchDistanceLimit($roomBbox, $dimensionBbox);

            if ($distance > $limit) {
                continue;
            }

            $dimensionArea = $dimension['length_m'] * $dimension['width_m'];
            $areaPenalty = abs($dimensionArea - $area) / max($area, 0.01);
            $score = ($distance / max($limit, 0.0001)) + $areaPenalty;

            if ($best === null || $score < $best['score']) {
                $best = [
                    ...$dimension,
                    'score' => $score,
                    'source_refs' => $sourceRef !== [] ? [$sourceRef] : [],
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'length_m' => round((float) $best['length_m'], 3),
            'width_m' => round((float) $best['width_m'], 3),
            'perimeter_m' => round(((float) $best['length_m'] + (float) $best['width_m']) * 2, 2),
            'basis' => 'dimension_pair_geometry',
            'source_refs' => $best['source_refs'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dimensionElements
     * @param array<string, float> $roomBbox
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromOrthogonalDimensions(array $dimensionElements, float $area, array $roomBbox, ?int $roomPage): ?array
    {
        $horizontal = [];
        $vertical = [];

        foreach ($dimensionElements as $element) {
            $lengthM = $this->linearDimensionMeters($element);

            if ($lengthM === null) {
                continue;
            }

            $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
            $dimensionPage = $this->nullableInt($sourceRef['page_number'] ?? $element['page_number'] ?? null);

            if ($roomPage !== null && $dimensionPage !== null && $roomPage !== $dimensionPage) {
                continue;
            }

            $dimensionBbox = $this->normalizedBbox($element['bbox'] ?? $sourceRef['bbox'] ?? null);

            if ($dimensionBbox === null) {
                continue;
            }

            $orientation = $this->dimensionOrientation($dimensionBbox);

            if ($orientation === 'unknown') {
                continue;
            }

            $distance = $this->bboxCenterDistance($roomBbox, $dimensionBbox);
            $limit = $this->dimensionMatchDistanceLimit($roomBbox, $dimensionBbox);

            if ($distance > $limit) {
                continue;
            }

            $candidate = [
                'length_m' => $lengthM,
                'distance_score' => $distance / max($limit, 0.0001),
                'source_ref' => $sourceRef,
            ];

            if ($orientation === 'horizontal') {
                $horizontal[] = $candidate;
            } else {
                $vertical[] = $candidate;
            }
        }

        $best = null;

        foreach ($horizontal as $horizontalDimension) {
            foreach ($vertical as $verticalDimension) {
                $lengthM = max((float) $horizontalDimension['length_m'], (float) $verticalDimension['length_m']);
                $widthM = min((float) $horizontalDimension['length_m'], (float) $verticalDimension['length_m']);

                if (!$this->isDimensionAreaPlausible($area, $lengthM, $widthM)) {
                    continue;
                }

                $dimensionArea = $lengthM * $widthM;
                $areaPenalty = abs($dimensionArea - $area) / max($area, 0.01);
                $score = (float) $horizontalDimension['distance_score']
                    + (float) $verticalDimension['distance_score']
                    + ($areaPenalty * 1.5);

                if ($best === null || $score < $best['score']) {
                    $sourceRefs = array_values(array_filter([
                        $horizontalDimension['source_ref'] ?? null,
                        $verticalDimension['source_ref'] ?? null,
                    ], 'is_array'));

                    $best = [
                        'length_m' => $lengthM,
                        'width_m' => $widthM,
                        'score' => $score,
                        'source_refs' => $sourceRefs,
                    ];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'length_m' => round((float) $best['length_m'], 3),
            'width_m' => round((float) $best['width_m'], 3),
            'perimeter_m' => round(((float) $best['length_m'] + (float) $best['width_m']) * 2, 2),
            'basis' => 'orthogonal_dimension_geometry',
            'source_refs' => $best['source_refs'],
        ];
    }

    private function firstSourceRefWithBbox(mixed $sourceRefs): ?array
    {
        if (!is_array($sourceRefs)) {
            return null;
        }

        foreach ($sourceRefs as $sourceRef) {
            if (is_array($sourceRef) && $this->normalizedBbox($sourceRef['bbox'] ?? null) !== null) {
                return $sourceRef;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $element
     * @return array{length_m: float, width_m: float}|null
     */
    private function dimensionPairMeters(array $element): ?array
    {
        $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
        $length = $this->numericValue($payload['length_mm'] ?? $payload['length'] ?? null);
        $width = $this->numericValue($payload['width_mm'] ?? $payload['width'] ?? null);

        if ($length === null || $width === null) {
            return null;
        }

        $lengthM = $length > 100 ? $length / 1000 : $length;
        $widthM = $width > 100 ? $width / 1000 : $width;

        if ($lengthM < 0.5 || $widthM < 0.5 || $lengthM > 50 || $widthM > 50) {
            return null;
        }

        return [
            'length_m' => round($lengthM, 3),
            'width_m' => round($widthM, 3),
        ];
    }

    private function linearDimensionMeters(array $element): ?float
    {
        $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
        $valueM = $this->numericValue($payload['value_m'] ?? null);

        if ($valueM === null) {
            $valueMm = $this->numericValue($payload['value_mm'] ?? null);
            $valueM = $valueMm !== null ? $valueMm / 1000 : null;
        }

        if ($valueM === null || $valueM < 0.5 || $valueM > 50) {
            return null;
        }

        return round($valueM, 3);
    }

    /**
     * @param array<string, float> $bbox
     */
    private function dimensionOrientation(array $bbox): string
    {
        $width = max((float) ($bbox['width'] ?? 0.0), 0.0001);
        $height = max((float) ($bbox['height'] ?? 0.0), 0.0001);

        if ($width >= $height * 1.8) {
            return 'horizontal';
        }

        if ($height >= $width * 1.8) {
            return 'vertical';
        }

        return 'unknown';
    }

    private function isDimensionAreaPlausible(float $area, float $lengthM, float $widthM): bool
    {
        $dimensionArea = $lengthM * $widthM;

        return $dimensionArea >= $area * 0.6 && $dimensionArea <= $area * 1.8;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function isOpeningDimensionElement(array $element): bool
    {
        $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
        $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
        $text = mb_strtolower(implode(' ', array_filter([
            (string) ($element['label'] ?? ''),
            (string) ($payload['line'] ?? ''),
            (string) ($sourceRef['excerpt'] ?? ''),
        ])));

        return $this->containsAny($text, [
            'двер',
            'окн',
            'ворот',
            'проем',
            'проём',
            'door',
            'window',
            'gate',
        ]);
    }

    /**
     * @param array<string, float> $left
     * @param array<string, float> $right
     */
    private function bboxCenterDistance(array $left, array $right): float
    {
        $leftX = $left['x'] + ($left['width'] / 2);
        $leftY = $left['y'] + ($left['height'] / 2);
        $rightX = $right['x'] + ($right['width'] / 2);
        $rightY = $right['y'] + ($right['height'] / 2);

        return sqrt((($leftX - $rightX) ** 2) + (($leftY - $rightY) ** 2));
    }

    /**
     * @param array<string, float> $roomBbox
     * @param array<string, float> $dimensionBbox
     */
    private function dimensionMatchDistanceLimit(array $roomBbox, array $dimensionBbox): float
    {
        if ($this->usesNormalizedCoordinateSpace($roomBbox, $dimensionBbox)) {
            return 0.25;
        }

        return max(
            240.0,
            max($roomBbox['width'], $roomBbox['height'], $dimensionBbox['width'], $dimensionBbox['height']) * 4
        );
    }

    /**
     * @param array<string, float> $left
     * @param array<string, float> $right
     */
    private function usesNormalizedCoordinateSpace(array $left, array $right): bool
    {
        $maxCoordinate = max(
            $left['x'] + $left['width'],
            $left['y'] + $left['height'],
            $right['x'] + $right['width'],
            $right['y'] + $right['height']
        );

        return $maxCoordinate <= 2.0;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     * @return array{height_m: float, source: string, source_refs: array<int, array<string, mixed>>}
     */
    private function heightEvidence(array $elements): array
    {
        $heights = [];
        $sourceRefs = [];

        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'height') {
                continue;
            }

            $height = $this->numericValue($element['value_number'] ?? null);

            if ($height === null || $height < 2.0 || $height > 6.0) {
                continue;
            }

            $heights[] = $height;

            if (is_array($element['source_ref'] ?? null)) {
                $sourceRefs[] = $element['source_ref'];
            }
        }

        if ($heights === []) {
            return [
                'height_m' => 2.7,
                'source' => 'default_assumption',
                'source_refs' => [],
            ];
        }

        return [
            'height_m' => round(array_sum($heights) / count($heights), 3),
            'source' => 'drawing_height',
            'source_refs' => array_slice($sourceRefs, 0, 10),
        ];
    }

    private function isWetRoomLabel(string $label): bool
    {
        $normalized = mb_strtolower($label);

        return $this->containsAny($normalized, [
            'сануз',
            'ванн',
            'душ',
            'туалет',
            'wc',
            'bath',
            'shower',
            'toilet',
        ]);
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
            $heightCount = count(array_filter(
                $pageElements,
                static fn (array $element): bool => ($element['type'] ?? null) === 'height'
            ));
            $specificationCount = count(array_filter(
                $pageTakeoffs,
                static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'specification_quantity'
            ));
            $workVolumeStatementCount = count(array_filter(
                $pageTakeoffs,
                static function (array $takeoff): bool {
                    $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];

                    return ($payload['source'] ?? null) === 'work_volume_statement';
                }
            ));
            $text = mb_strtolower($page->text . ' ' . $filename);
            $hasPlanSignal = preg_match('/план|планировка|экспликац/ui', $text) === 1;
            $hasWorkVolumeStatementSignal = $workVolumeStatementCount > 0
                || preg_match('/ведомость\s+(?:объемов|объёмов|работ)|объемы?\s+работ|объёмы?\s+работ|вор\b/ui', $text) === 1;
            $hasSpecificationSignal = $specificationCount > 0 || preg_match('/спецификац|ведомость|экспликац/ui', $text) === 1;
            $hasEstimateSignal = preg_match('/(?:гэсн|фер|тер)?\s*\d{2}-\d{2}-\d{3}-\d{2,3}|смет/ui', $text) === 1;
            $role = $this->pageRole($hasPlanSignal, $hasWorkVolumeStatementSignal, $hasSpecificationSignal, $hasEstimateSignal, $roomCount, $dimensionCount);

            $profiles[] = [
                'page_number' => $pageNumber,
                'page_role' => $role,
                'confidence' => $this->pageRoleConfidence($page, $role, $hasPlanSignal, $roomCount, $dimensionCount),
                'signals' => array_values(array_filter([
                    $hasPlanSignal ? 'plan_keywords' : null,
                    $hasWorkVolumeStatementSignal ? 'work_volume_statement_keywords' : null,
                    $hasSpecificationSignal ? 'specification_keywords' : null,
                    $hasEstimateSignal ? 'estimate_or_norm_keywords' : null,
                    $roomCount > 0 ? 'room_areas' : null,
                    $dimensionCount > 0 ? 'dimensions' : null,
                    $heightCount > 0 ? 'height' : null,
                    $workVolumeStatementCount > 0 ? 'work_volume_statement_quantities' : null,
                    $specificationCount > 0 ? 'specification_quantities' : null,
                ])),
                'room_area_count' => $roomCount,
                'dimension_count' => $dimensionCount,
                'height_count' => $heightCount,
                'work_volume_statement_quantity_count' => $workVolumeStatementCount,
                'specification_quantity_count' => $specificationCount,
            ];
        }

        return $profiles;
    }

    private function pageRole(
        bool $hasPlanSignal,
        bool $hasWorkVolumeStatementSignal,
        bool $hasSpecificationSignal,
        bool $hasEstimateSignal,
        int $roomCount,
        int $dimensionCount
    ): string {
        if (($hasPlanSignal && ($roomCount > 0 || $dimensionCount > 0)) || $roomCount >= 2) {
            return 'floor_plan';
        }

        if ($hasWorkVolumeStatementSignal) {
            return 'work_volume_statement';
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
        $heightCount = count(array_filter(
            $elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'height'
        ));
        $heightEvidence = $this->heightEvidence($elements);
        $detectedHeight = $heightEvidence['source'] === 'drawing_height' ? $heightEvidence['height_m'] : null;
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
            'height_count' => $heightCount,
            'detected_height_m' => $detectedHeight,
            'evidence_graph' => $this->evidenceGraph($takeoffs),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $takeoffs
     * @return array<string, mixed>
     */
    private function evidenceGraph(array $takeoffs): array
    {
        $nodes = [];
        $reviewRequiredCount = 0;
        $lowConfidenceCount = 0;
        $missingSourceRefsCount = 0;

        foreach (array_values($takeoffs) as $index => $takeoff) {
            $sourceRefs = array_values(array_filter($takeoff['source_refs'] ?? [], 'is_array'));
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $confidence = (float) ($takeoff['confidence'] ?? 0);
            $reviewRequired = (bool) ($payload['review_required'] ?? false);

            if ($reviewRequired) {
                $reviewRequiredCount++;
            }

            if ($confidence < 0.7) {
                $lowConfidenceCount++;
            }

            if ($sourceRefs === []) {
                $missingSourceRefsCount++;
            }

            if (count($nodes) >= 40) {
                continue;
            }

            $nodes[] = [
                'id' => 'takeoff-' . ($index + 1),
                'type' => 'quantity_takeoff',
                'scope_key' => $takeoff['scope_key'] ?? null,
                'quantity_key' => $payload['quantity_key'] ?? null,
                'name' => $takeoff['name'] ?? null,
                'quantity' => $takeoff['quantity'] ?? null,
                'unit' => $takeoff['unit'] ?? null,
                'confidence' => round(max(min($confidence, 1.0), 0.0), 4),
                'requires_review' => $reviewRequired,
                'source_refs_count' => count($sourceRefs),
                'source_refs' => array_slice($sourceRefs, 0, 3),
            ];
        }

        return [
            'nodes_count' => count($takeoffs),
            'nodes' => $nodes,
            'review_required_count' => $reviewRequiredCount,
            'low_confidence_count' => $lowConfidenceCount,
            'missing_source_refs_count' => $missingSourceRefsCount,
            'quality_level' => $reviewRequiredCount > 0 || $lowConfidenceCount > 0 || $missingSourceRefsCount > 0
                ? 'review_required'
                : 'ready',
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

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
