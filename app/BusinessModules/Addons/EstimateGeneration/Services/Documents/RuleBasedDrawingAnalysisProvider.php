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
        private readonly QuantityStatementLineParser $quantityLineParser = new QuantityStatementLineParser,
    ) {}

    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData
    {
        $elements = [];
        $takeoffs = [];

        foreach ($recognition->pages as $page) {
            $pageLineRecords = $this->lineRecords($page);

            array_push($elements, ...$this->titleBlockElements($documentId, $filename, $page, $pageLineRecords));

            foreach ($pageLineRecords as $lineRecord) {
                $line = $lineRecord['text'];
                $lineElements = [
                    ...$this->scaleElements($documentId, $filename, $page, $line),
                    ...$this->axisElements($documentId, $filename, $page, $line),
                    ...$this->heightElements($documentId, $filename, $page, $line),
                    ...$this->roomLabelElements($documentId, $filename, $page, $line),
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

        array_push($takeoffs, ...$this->roomAreaTakeoffsFromDimensionLabels($elements));

        $roomTakeoffs = array_values(array_filter(
            $takeoffs,
            static fn (array $takeoff): bool => ($takeoff['scope_key'] ?? null) === 'room_area'
        ));
        array_push($takeoffs, ...$this->aggregateRoomTakeoffs($roomTakeoffs, $elements));

        if ($roomTakeoffs === []) {
            array_push($takeoffs, ...$this->aggregateFootprintDimensionTakeoffs($filename, $recognition, $elements));
        }

        $pageProfiles = $this->pageProfiles($filename, $recognition->pages, $elements, $takeoffs);
        $summary = $this->summary($filename, $recognition, $elements, $takeoffs, $roomTakeoffs, $pageProfiles);

        return new DrawingAnalysisResultData(
            elements: $this->unique($elements, ['type', 'label', 'value_text', 'page_number']),
            takeoffs: $this->unique($takeoffs, ['scope_key', 'name', 'unit', 'quantity', 'page_number']),
            summary: $summary,
        );
    }

    /**
     * @param  array<int, array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}>  $lineRecords
     * @return array<int, array<string, mixed>>
     */
    private function titleBlockElements(int $documentId, string $filename, OcrPageResult $page, array $lineRecords): array
    {
        $sheetMark = null;
        $sheetNumber = null;
        $sheetName = null;
        $stage = null;
        $revision = null;
        $scaleDenominator = null;
        $objectName = null;
        $sourceLines = [];

        foreach (array_slice($lineRecords, 0, 40) as $lineRecord) {
            $line = trim($lineRecord['text']);

            if ($line === '') {
                continue;
            }

            if ($objectName === null && preg_match('/(?:^|[^\p{L}\p{N}])(?:объект|наименование объекта)\s*[:\-]\s*(?<name>.+)$/iu', $line, $match) === 1) {
                $objectName = $this->cleanTitleBlockValue((string) $match['name']);
                $sourceLines[] = $line;
            }

            if ($sheetMark === null && preg_match('/(?<![\p{L}\p{N}])(?<code>АР|АС|КР|КЖ|КМ|ОВ|ВК|НВК|ЭОМ|ЭО|ЭС|СС|ПС|ПТ|ГП|ТХ|AR|AS|KR|KJ|KM|OV|VK|EOM|EO|ES|SS|GP)\s*[-.]?\s*(?<number>\d+(?:[.\-]\d+)*)(?:\s+(?<name>[\p{L}\p{N}\s.,;:()\/\-]{3,120}))?/iu', $line, $match) === 1) {
                $sheetMark = mb_strtoupper((string) $match['code']).'-'.(string) $match['number'];
                $sheetName = $this->cleanTitleBlockValue((string) ($match['name'] ?? '')) ?: null;
                $sourceLines[] = $line;
            }

            if ($stage === null && preg_match('/(?:^|[^\p{L}\p{N}])(?:стадия|стад\.?)\s*[:\-]?\s*(?<stage>[A-ZА-Я]{1,4})(?:$|[^\p{L}\p{N}])/iu', $line, $match) === 1) {
                $stage = mb_strtoupper((string) $match['stage']);
                $sourceLines[] = $line;
            }

            if ($sheetNumber === null && preg_match('/(?<![\p{L}\p{N}])(?:лист|л\.)\s*[:\-]?\s*(?<number>\d+[A-ZА-Я]?)(?:$|[^\p{L}\p{N}])/iu', $line, $match) === 1) {
                $sheetNumber = (string) $match['number'];
                $sourceLines[] = $line;
            }

            if ($revision === null && preg_match('/(?:^|[^\p{L}\p{N}])(?:изм\.?|изменение|rev\.?)\s*[:\-]?\s*(?<revision>\d+[A-ZА-Я]?)(?:$|[^\p{L}\p{N}])/iu', $line, $match) === 1) {
                $revision = mb_strtoupper((string) $match['revision']);
                $sourceLines[] = $line;
            }

            if ($scaleDenominator === null && preg_match('/(?:масштаб|м|scale)\s*[: ]?\s*1\s*:\s*(?<scale>\d+)/iu', $line, $match) === 1) {
                $scaleDenominator = (int) $match['scale'];
                $sourceLines[] = $line;
            }
        }

        if ($sheetMark === null && $sheetName === null && ! ($stage !== null && $sheetNumber !== null)) {
            return [];
        }

        $sourceLines = array_values(array_unique(array_slice($sourceLines, 0, 12)));
        $valueParts = array_values(array_filter([$sheetMark, $sheetName]));
        $discipline = $this->titleBlockDiscipline($sheetMark);

        return [[
            'type' => 'title_block',
            'label' => $sheetName ?? $sheetMark ?? 'Штамп листа',
            'value_text' => $valueParts !== [] ? implode(' · ', $valueParts) : 'Лист '.$sheetNumber,
            'value_number' => null,
            'unit' => null,
            'bbox' => null,
            'geometry' => null,
            'confidence' => $this->confidence($page),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, implode(' | ', $sourceLines)),
            'normalized_payload' => [
                'sheet_mark' => $sheetMark,
                'sheet_number' => $sheetNumber,
                'sheet_name' => $sheetName,
                'stage' => $stage,
                'revision' => $revision,
                'scale_denominator' => $scaleDenominator,
                'discipline' => $discipline,
                'object_name' => $objectName,
                'source_lines' => $sourceLines,
                'title_block_detected' => true,
            ],
            'page_number' => $page->pageNumber,
        ]];
    }

    private function cleanTitleBlockValue(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return trim($value, " \t\n\r\0\x0B:-—–.");
    }

    private function titleBlockDiscipline(?string $sheetMark): ?string
    {
        if ($sheetMark === null) {
            return null;
        }

        $code = mb_strtoupper((string) preg_replace('/[-.\d\s].*$/u', '', $sheetMark));

        return match ($code) {
            'АР', 'АС', 'AR', 'AS' => 'architecture',
            'КР', 'КЖ', 'КМ', 'KR', 'KJ', 'KM' => 'structural',
            'ОВ', 'OV' => 'hvac',
            'ВК', 'НВК', 'VK' => 'water_sewer',
            'ЭОМ', 'ЭО', 'ЭС', 'EOM', 'EO', 'ES' => 'electrical',
            'СС', 'ПС', 'ПТ', 'SS' => 'low_current',
            'ГП', 'GP' => 'site_plan',
            default => 'technical',
        };
    }

    private function axisElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $labels = $this->axisLabelsFromLine($line);

        if ($labels === []) {
            return [];
        }

        return array_map(
            fn (string $label): array => [
                'type' => 'axis',
                'label' => 'Ось '.$label,
                'value_text' => $label,
                'value_number' => is_numeric($label) ? (float) $label : null,
                'unit' => null,
                'bbox' => null,
                'geometry' => null,
                'confidence' => max($this->confidence($page) - 0.05, 0.35),
                'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
                'normalized_payload' => [
                    'line' => $line,
                    'axis_label' => $label,
                    'axis_source' => 'ocr_text',
                ],
                'page_number' => $page->pageNumber,
            ],
            $labels
        );
    }

    /**
     * @return array<int, string>
     */
    private function axisLabelsFromLine(string $line): array
    {
        if (preg_match('/(?:^|[^\p{L}\p{N}])(?:оси|ось|axis|gridline|grid)(?:$|[^\p{L}\p{N}])/iu', $line) !== 1) {
            return [];
        }

        $tail = preg_replace('/^.*?(?:оси|ось|axis|gridline|grid)\s*[:№#-]?\s*/iu', '', $line, 1) ?? $line;
        $tail = preg_replace('/\s+и\s+/iu', ' ', $tail) ?? $tail;
        $labels = [];

        if (preg_match_all('/(?<!\d)(?<start>\d{1,3})\s*[-–—]\s*(?<end>\d{1,3})(?!\d)/u', $tail, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $start = (int) $match['start'];
                $end = (int) $match['end'];

                if ($start > 0 && $end >= $start && ($end - $start) <= 30) {
                    foreach (range($start, $end) as $axis) {
                        $labels[] = (string) $axis;
                    }
                }
            }
        }

        if (preg_match_all('/(?<![\p{L}])(?<start>[A-ZА-Я])\s*[-–—]\s*(?<end>[A-ZА-Я])(?![\p{L}])/iu', $tail, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                array_push($labels, ...$this->expandAxisLetterRange((string) $match['start'], (string) $match['end']));
            }
        }

        if (preg_match_all('/(?<![\p{L}\p{N}])(?:[A-ZА-Я]{1,2}|\d{1,3})(?![\p{L}\p{N}])/iu', $tail, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $labels[] = mb_strtoupper((string) $match);
            }
        }

        return array_values(array_slice(array_unique(array_filter(
            $labels,
            static fn (string $label): bool => $label !== ''
        )), 0, 40));
    }

    /**
     * @return array<int, string>
     */
    private function expandAxisLetterRange(string $start, string $end): array
    {
        $start = mb_strtoupper($start);
        $end = mb_strtoupper($end);
        $alphabets = [
            ['А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж', 'З', 'И', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Э', 'Ю', 'Я'],
            range('A', 'Z'),
        ];

        foreach ($alphabets as $alphabet) {
            $startIndex = array_search($start, $alphabet, true);
            $endIndex = array_search($end, $alphabet, true);

            if ($startIndex === false || $endIndex === false) {
                continue;
            }

            if (abs($endIndex - $startIndex) > 20) {
                return [$start, $end];
            }

            $slice = array_slice($alphabet, min($startIndex, $endIndex), abs($endIndex - $startIndex) + 1);

            return $startIndex <= $endIndex ? $slice : array_reverse($slice);
        }

        return [$start, $end];
    }

    private function scaleElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (preg_match('/(?:масштаб|м)\s*[: ]?\s*1\s*:\s*(?<scale>\d+)/iu', $line, $match) !== 1) {
            return [];
        }

        return [[
            'type' => 'scale',
            'label' => 'Масштаб',
            'value_text' => '1:'.$match['scale'],
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
            'value_text' => $this->formatNumber($height).' м',
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

    private function roomLabelElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        $label = $this->roomLabelWithoutArea($line);

        if ($label === null) {
            return [];
        }

        return [[
            'type' => 'room_label',
            'label' => $label,
            'value_text' => null,
            'value_number' => null,
            'unit' => null,
            'bbox' => null,
            'geometry' => null,
            'confidence' => max($this->confidence($page) - 0.05, 0.35),
            'source_ref' => $this->sourceRef($documentId, $filename, $page, $line),
            'normalized_payload' => [
                'line' => $line,
                'quantity_key' => 'finish.floor',
                'room_label' => $label,
                'room_label_detected' => true,
                'area_source' => 'dimension_geometry',
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
                'label' => $match['label'] !== '' ? $match['label'] : 'Помещение '.($index + 1),
                'value_text' => $this->formatNumber($match['area']).' м2',
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
                    'room_label_detected' => $match['label'] !== '',
                ],
                'page_number' => $page->pageNumber,
            ];
        }

        return $elements;
    }

    private function roomLabelWithoutArea(string $line): ?string
    {
        if ($this->roomAreaMatches($line) !== []) {
            return null;
        }

        $label = trim((string) preg_replace('/\s+/u', ' ', $line));

        if ($label === '' || mb_strlen($label) > 48) {
            return null;
        }

        if (
            $this->linearDimensionFromLine($label) !== null
            || preg_match('/\d+(?:[,.]\d+)?\s*[xх×]\s*\d+(?:[,.]\d+)?/iu', $label) === 1
            || preg_match('/(?:\d{2}-\d{2}-\d{3}|[,.]\d{1,2}\s*(?:м2|м²|m2|m²)|%|₽|rub|руб)/iu', $label) === 1
        ) {
            return null;
        }

        $label = trim((string) preg_replace('/^(?:пом(?:ещ)?\.?|room|№|#)?\s*\d+[.\-: ]*/iu', '', $label));

        if ($label === '') {
            return null;
        }

        $normalized = mb_strtolower($label);

        if ($this->containsAny($normalized, [
            'план',
            'масштаб',
            'высот',
            'размер',
            'двер',
            'окн',
            'проем',
            'проём',
            'вентиляц',
            'водоснаб',
            'канализац',
            'отоплен',
            'кабель',
            'спецификац',
            'ведомост',
            'смет',
        ])) {
            return null;
        }

        if (! $this->containsAny($normalized, [
            'гостиная',
            'кухня',
            'санузел',
            'ванная',
            'туалет',
            'спальня',
            'детская',
            'кабинет',
            'прихожая',
            'коридор',
            'холл',
            'гардероб',
            'кладовая',
            'постирочная',
            'тамбур',
            'котельная',
            'бойлерная',
            'living',
            'kitchen',
            'bedroom',
            'bathroom',
            'toilet',
            'hall',
            'corridor',
            'wardrobe',
            'storage',
        ])) {
            return null;
        }

        return $label;
    }

    private function openingElements(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        if (
            preg_match('/(?<label>(?:окно|дверь|ворота|ок|дп|ду)[\w\- ]*)\s+(?<width>\d{2,5})\s*[xх×]\s*(?<height>\d{2,5})(?:\s*[-–]\s*(?<count>\d+)\s*шт)?/iu', $line, $match) !== 1
            && preg_match('/(?<label>(?:окно|дверь|ворота|ок|дп|ду)[\p{L}\p{N}_\- ]*?)\s+(?<width>\d{3,5})\s+(?<height>\d{3,5})(?:\s*(?:[-–]\s*)?(?<count>\d+)\s*шт)?/iu', $line, $match) !== 1
        ) {
            return [];
        }

        $label = trim($match['label']);

        return [[
            'type' => 'opening',
            'label' => $label,
            'value_text' => $match['width'].'x'.$match['height'],
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
            'value_text' => $this->formatNumber($this->number($match['length'])).' м',
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
                'value_text' => $this->formatNumber((float) $item['quantity']).' '.$item['unit'],
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
            'value_text' => $this->formatNumber($item['quantity']).' '.$item['unit'],
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
                'value_text' => $match['length'].'x'.$match['width'],
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
            $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];

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
                    'room_label_detected' => (bool) ($payload['room_label_detected'] ?? false),
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
            'formula' => $this->formatNumber($item['quantity']).' '.$item['unit'],
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

        if (! is_string($value)) {
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

        if (! $this->containsAny($normalized, ['двер', 'окн', 'ворот', 'дп-', 'ду-', 'ок-', 'проем', 'проём', 'door', 'window', 'gate'])) {
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
            if (! is_array($block)) {
                continue;
            }

            $blockBbox = $this->normalizedBbox($block['bounding_box'] ?? $block['bbox'] ?? null);
            $lines = is_array($block['lines'] ?? null) ? $block['lines'] : [];

            foreach ($lines as $lineIndex => $line) {
                if (! is_array($line)) {
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
     * @param  array<string, mixed>  $line
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
     * @param  array<string, float>|null  $bbox
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
            'line_hash' => sha1($pageNumber.'|'.($blockIndex ?? 'text').'|'.($lineIndex ?? 'text').'|'.$text),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}  $lineRecord
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
     * @param  array<int, array<string, mixed>>  $takeoffs
     * @param  array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}  $lineRecord
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
     * @param  array<string, mixed>  $sourceRef
     * @param  array{text: string, bbox: array<string, float>|null, block_index: int|null, line_index: int|null, line_hash: string}  $lineRecord
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
     * @param  array<int, mixed>  $words
     * @return array<string, float>|null
     */
    private function wordsBbox(array $words): ?array
    {
        $boxes = [];

        foreach ($words as $word) {
            if (! is_array($word)) {
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
        if (! is_array($bbox)) {
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
     * @param  array<int, string>  $keys
     */
    private function hasNumericKeys(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! is_numeric($value[$key] ?? null)) {
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
        if (! is_array($points)) {
            return null;
        }

        $coordinates = [];

        foreach ($points as $point) {
            if (! is_array($point)) {
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
        $text = mb_strtolower($filename.' '.$line);

        return preg_match('/ведомость\s+(?:объемов|объёмов|работ)|объемы?\s+работ|объёмы?\s+работ|(?:^|[^\p{L}\p{N}])вор(?:$|[^\p{L}\p{N}])/u', $text) === 1
            ? 'work_volume_statement'
            : 'specification';
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<int, array<string, mixed>>
     */
    private function roomAreaTakeoffsFromDimensionLabels(array $elements): array
    {
        $dimensionElements = $this->dimensionElementsForRooms($elements);

        if ($dimensionElements === []) {
            return [];
        }

        $takeoffs = [];

        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'room_label') {
                continue;
            }

            $geometry = $this->roomGeometryFromLabelElement($element, $dimensionElements);

            if ($geometry === null) {
                continue;
            }

            $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
            $label = (string) ($payload['room_label'] ?? $element['label'] ?? 'Помещение');
            $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
            $quantity = round((float) $geometry['length_m'] * (float) $geometry['width_m'], 2);
            $sourceRefs = array_slice(array_values(array_filter([
                $sourceRef !== [] ? $sourceRef : null,
                ...$geometry['source_refs'],
            ], 'is_array')), 0, 10);

            $takeoffs[] = [
                'source_element_ids' => [],
                'scope_key' => 'room_area',
                'work_intent' => ['scope' => 'finishing', 'basis' => 'dimension_room_area'],
                'name' => $label,
                'unit' => 'м2',
                'quantity' => $quantity,
                'formula' => $label.': '.$this->formatNumber((float) $geometry['length_m']).' x '.$this->formatNumber((float) $geometry['width_m']).' = '.$this->formatNumber($quantity).' м2',
                'confidence' => max((float) ($element['confidence'] ?? 0.72) - 0.12, 0.35),
                'source_refs' => $sourceRefs,
                'normalized_payload' => [
                    'line' => (string) ($payload['line'] ?? ''),
                    'room_label' => $label,
                    'room_label_detected' => true,
                    'quantity_key' => 'finish.floor',
                    'calculation_basis' => $geometry['basis'],
                    'review_required' => true,
                    'review_reason' => 'dimension_derived_room_area',
                    'review_reasons' => ['dimension_derived_room_area'],
                    'length_m' => $geometry['length_m'],
                    'width_m' => $geometry['width_m'],
                    'perimeter_m' => $geometry['perimeter_m'],
                ],
                'page_number' => (int) ($element['page_number'] ?? ($sourceRef['page_number'] ?? 0)),
            ];
        }

        return $takeoffs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $roomTakeoffs
     * @param  array<int, array<string, mixed>>  $elements
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
        $openingAdjustment = $this->openingAdjustment($elements);
        $totalArea = 0.0;
        $estimatedWallArea = 0.0;
        $estimatedBaseboardLength = 0.0;
        $wetRoomArea = 0.0;
        $wetRoomWallArea = 0.0;
        $sourceRefs = [];
        $dimensionSourceRefs = [];
        $roomDimensions = [];
        $labeledRoomCount = 0;
        $dimensionDerivedRoomAreaCount = 0;
        $confidence = 0.0;

        foreach ($roomTakeoffs as $takeoff) {
            $area = (float) ($takeoff['quantity'] ?? 0);
            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $roomLabel = (string) ($payload['room_label'] ?? $takeoff['name'] ?? '');
            $roomLabelDetected = (bool) ($payload['room_label_detected'] ?? false);
            $dimensionDerivedRoomArea = ($payload['review_reason'] ?? null) === 'dimension_derived_room_area'
                || in_array('dimension_derived_room_area', is_array($payload['review_reasons'] ?? null) ? $payload['review_reasons'] : [], true);

            $totalArea += $area;
            $labeledRoomCount += $roomLabelDetected ? 1 : 0;
            $dimensionDerivedRoomAreaCount += $dimensionDerivedRoomArea ? 1 : 0;
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
        $unlabeledRoomCount = max($roomCount - $labeledRoomCount, 0);
        $unlabeledRoomShare = $roomCount > 0 ? $unlabeledRoomCount / $roomCount : 0.0;
        $footprintCoverage = $this->footprintCoverage($elements, $totalArea);
        $reviewReasons = array_values(array_filter([
            $unlabeledRoomCount > 0 && ($labeledRoomCount === 0 || $unlabeledRoomShare >= 0.5)
                ? 'unlabeled_room_areas'
                : null,
            ($footprintCoverage['review_required'] ?? false) === true
                ? 'room_area_footprint_mismatch'
                : null,
            $dimensionDerivedRoomAreaCount > 0
                ? 'dimension_derived_room_area'
                : null,
        ]));
        $roomAreaReviewRequired = $reviewReasons !== [];
        $roomAreaQualityPayload = [
            'labeled_room_count' => $labeledRoomCount,
            'unlabeled_room_count' => $unlabeledRoomCount,
            'dimension_derived_room_area_count' => $dimensionDerivedRoomAreaCount,
            ...$footprintCoverage['payload'],
        ];

        if ($reviewReasons !== []) {
            $roomAreaQualityPayload['review_reasons'] = $reviewReasons;
            $roomAreaQualityPayload['review_reason'] = count($reviewReasons) === 1
                ? $reviewReasons[0]
                : 'multiple_room_area_quality_issues';
        }

        $totalArea = round($totalArea, 2);
        $confidence = round($confidence / max($roomCount, 1), 4);
        $sourceRefs = array_slice($sourceRefs, 0, 50);
        $dimensionSourceRefs = array_slice($dimensionSourceRefs, 0, 50);
        $roomAreaSourceRefs = array_slice([
            ...$sourceRefs,
            ...$footprintCoverage['source_refs'],
        ], 0, 50);
        $perimeterSourceRefs = array_slice([
            ...$sourceRefs,
            ...$dimensionSourceRefs,
            ...$openingAdjustment['source_refs'],
        ], 0, 50);
        $calculationSourceRefs = array_slice([
            ...$perimeterSourceRefs,
            ...$heightEvidence['source_refs'],
        ], 0, 50);
        $roomDimensionCount = count($roomDimensions);
        $perimeterCalculationBasis = $roomDimensionCount > 0
            ? 'room_dimension_geometry'
            : 'estimated_room_perimeter';

        $grossBaseboardLength = round($estimatedBaseboardLength, 2);
        $grossWallArea = round($estimatedWallArea > 0 ? $estimatedWallArea : $totalArea * $heightM, 2);
        $openingArea = min((float) $openingAdjustment['opening_area_m2'], max($grossWallArea - 0.01, 0.0));
        $doorWidth = min((float) $openingAdjustment['door_width_m'], $grossBaseboardLength);
        $baseboardLength = round(max($grossBaseboardLength - $doorWidth, 0.0), 2);
        $wallArea = round(max($grossWallArea - $openingArea, 0.01), 2);
        $wallOpeningFormulaPart = $openingArea > 0
            ? '; минус проемы '.$this->formatNumber($openingArea).' м2'
            : '';
        $baseboardOpeningFormulaPart = $doorWidth > 0
            ? '; минус дверные проемы '.$this->formatNumber($doorWidth).' м'
            : '';
        $heightFormulaPart = ' x '.$this->formatNumber($heightM).' м';
        $aggregates = [
            $this->aggregateTakeoff(
                scopeKey: 'floor_finish_area',
                quantityKey: 'finish.floor',
                name: 'Площадь чистовой отделки пола по планировке',
                quantity: $totalArea,
                formula: 'Сумма площадей помещений: '.$this->formatNumber($totalArea).' м2',
                confidence: $roomAreaReviewRequired ? max($confidence - 0.08, 0.35) : min($confidence + 0.03, 0.98),
                sourceRefs: $roomAreaSourceRefs,
                roomCount: $roomCount,
                reviewRequired: $roomAreaReviewRequired,
                extraPayload: $roomAreaQualityPayload
            ),
            $this->aggregateTakeoff(
                scopeKey: 'rough_floor_area',
                quantityKey: 'rough.floor',
                name: 'Площадь основания пола по планировке',
                quantity: $totalArea,
                formula: 'Сумма площадей помещений: '.$this->formatNumber($totalArea).' м2',
                confidence: $roomAreaReviewRequired ? max($confidence - 0.08, 0.35) : min($confidence + 0.02, 0.98),
                sourceRefs: $roomAreaSourceRefs,
                roomCount: $roomCount,
                reviewRequired: $roomAreaReviewRequired,
                extraPayload: $roomAreaQualityPayload
            ),
            $this->aggregateTakeoff(
                scopeKey: 'ceiling_finish_area',
                quantityKey: 'office.ceiling',
                name: 'Площадь потолков по планировке',
                quantity: $totalArea,
                formula: 'Принято по площади помещений: '.$this->formatNumber($totalArea).' м2',
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
                formula: 'Ориентировочная площадь стен по комнатам: сумма 4 x sqrt(S)'.$heightFormulaPart.$wallOpeningFormulaPart.' = '.$this->formatNumber($wallArea).' м2',
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
                    'gross_wall_area_m2' => $grossWallArea,
                    'opening_area_m2' => round($openingArea, 2),
                    'opening_count' => $openingAdjustment['opening_count'],
                    'openings_subtracted' => $openingArea > 0,
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'paint_area',
                quantityKey: 'finish.paint',
                name: 'Расчетная площадь окраски стен по планировке',
                quantity: $wallArea,
                formula: 'Ориентировочная площадь окраски принята по расчетной площади стен: '.$this->formatNumber($grossWallArea).' м2'.$wallOpeningFormulaPart.' = '.$this->formatNumber($wallArea).' м2',
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
                    'gross_wall_area_m2' => $grossWallArea,
                    'opening_area_m2' => round($openingArea, 2),
                    'opening_count' => $openingAdjustment['opening_count'],
                    'openings_subtracted' => $openingArea > 0,
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'skirting_length',
                quantityKey: 'finish.baseboard',
                name: 'Расчетная длина плинтуса по планировке',
                quantity: $baseboardLength,
                formula: 'Ориентировочная длина плинтуса: сумма 4 x sqrt(S)'.$baseboardOpeningFormulaPart.' = '.$this->formatNumber($baseboardLength).' м',
                confidence: max($confidence - 0.24, 0.35),
                sourceRefs: $perimeterSourceRefs,
                roomCount: $roomCount,
                reviewRequired: true,
                unit: 'м',
                extraPayload: [
                    'calculation_basis' => $perimeterCalculationBasis,
                    'room_dimension_count' => $roomDimensionCount,
                    'room_dimensions' => array_slice($roomDimensions, 0, 20),
                    'gross_baseboard_length_m' => $grossBaseboardLength,
                    'door_width_m' => round($doorWidth, 2),
                    'opening_count' => $openingAdjustment['opening_count'],
                    'openings_subtracted' => $doorWidth > 0,
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
                formula: 'Мокрые зоны: пол '.$this->formatNumber($wetRoomArea).' м2 + стены '.$this->formatNumber($wetRoomWallArea).' м2',
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
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{review_required: bool, payload: array<string, mixed>, source_refs: array<int, array<string, mixed>>}
     */
    private function footprintCoverage(array $elements, float $roomArea): array
    {
        $footprint = $this->bestFootprintDimensionPair($elements);

        if ($footprint === null || $roomArea <= 0) {
            return [
                'review_required' => false,
                'payload' => [],
                'source_refs' => [],
            ];
        }

        $footprintArea = round((float) $footprint['length_m'] * (float) $footprint['width_m'], 2);

        if ($footprintArea <= 0) {
            return [
                'review_required' => false,
                'payload' => [],
                'source_refs' => [],
            ];
        }

        $coverageRatio = round($roomArea / $footprintArea, 4);
        $missingArea = round(max($footprintArea - $roomArea, 0.0), 2);
        $reviewRequired = $footprintArea >= $roomArea * 1.25 && $missingArea >= 10.0;

        return [
            'review_required' => $reviewRequired,
            'payload' => [
                'footprint_area_m2' => $footprintArea,
                'footprint_length_m' => $footprint['length_m'],
                'footprint_width_m' => $footprint['width_m'],
                'room_to_footprint_area_ratio' => $coverageRatio,
                'missing_room_area_against_footprint_m2' => $missingArea,
                'footprint_coverage_review_required' => $reviewRequired,
            ],
            'source_refs' => array_values(array_filter($footprint['source_refs'], 'is_array')),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<int, array<string, mixed>>
     */
    private function aggregateFootprintDimensionTakeoffs(string $filename, OcrRecognitionResult $recognition, array $elements): array
    {
        if (! $this->hasFloorPlanTextSignal($filename, $recognition)) {
            return [];
        }

        $footprint = $this->bestFootprintDimensionPair($elements);

        if ($footprint === null) {
            return [];
        }

        $heightEvidence = $this->heightEvidence($elements);
        $openingAdjustment = $this->openingAdjustment($elements);
        $heightM = (float) $heightEvidence['height_m'];
        $area = round((float) $footprint['length_m'] * (float) $footprint['width_m'], 2);
        $perimeter = round(((float) $footprint['length_m'] + (float) $footprint['width_m']) * 2, 2);
        $grossWallArea = round($perimeter * $heightM, 2);
        $openingArea = min((float) $openingAdjustment['opening_area_m2'], max($grossWallArea - 0.01, 0.0));
        $doorWidth = min((float) $openingAdjustment['door_width_m'], $perimeter);
        $wallArea = round(max($grossWallArea - $openingArea, 0.01), 2);
        $baseboardLength = round(max($perimeter - $doorWidth, 0.0), 2);
        $sourceRefs = array_slice([
            ...$footprint['source_refs'],
            ...$openingAdjustment['source_refs'],
        ], 0, 50);
        $calculationSourceRefs = array_slice([
            ...$sourceRefs,
            ...$heightEvidence['source_refs'],
        ], 0, 50);
        $confidence = max((float) $footprint['confidence'] - 0.18, 0.35);
        $dimensionPayload = [
            'calculation_basis' => 'footprint_dimension_pair',
            'length_m' => $footprint['length_m'],
            'width_m' => $footprint['width_m'],
            'perimeter_m' => $perimeter,
            'review_reason' => 'overall_dimensions_without_room_areas',
        ];
        $openingPayload = [
            'opening_area_m2' => round($openingArea, 2),
            'door_width_m' => round($doorWidth, 2),
            'opening_count' => $openingAdjustment['opening_count'],
            'openings_subtracted' => $openingArea > 0 || $doorWidth > 0,
        ];
        $wallOpeningFormulaPart = $openingArea > 0
            ? '; минус проемы '.$this->formatNumber($openingArea).' м2'
            : '';
        $baseboardOpeningFormulaPart = $doorWidth > 0
            ? '; минус дверные проемы '.$this->formatNumber($doorWidth).' м'
            : '';

        return [
            $this->aggregateTakeoff(
                scopeKey: 'floor_finish_area',
                quantityKey: 'finish.floor',
                name: 'Площадь чистовой отделки пола по габаритам планировки',
                quantity: $area,
                formula: $this->formatNumber((float) $footprint['length_m']).' x '.$this->formatNumber((float) $footprint['width_m']).' = '.$this->formatNumber($area).' м2',
                confidence: $confidence,
                sourceRefs: $sourceRefs,
                roomCount: 0,
                reviewRequired: true,
                extraPayload: $dimensionPayload
            ),
            $this->aggregateTakeoff(
                scopeKey: 'rough_floor_area',
                quantityKey: 'rough.floor',
                name: 'Площадь основания пола по габаритам планировки',
                quantity: $area,
                formula: $this->formatNumber((float) $footprint['length_m']).' x '.$this->formatNumber((float) $footprint['width_m']).' = '.$this->formatNumber($area).' м2',
                confidence: $confidence,
                sourceRefs: $sourceRefs,
                roomCount: 0,
                reviewRequired: true,
                extraPayload: $dimensionPayload
            ),
            $this->aggregateTakeoff(
                scopeKey: 'wall_finish_area',
                quantityKey: 'rough.walls',
                name: 'Расчетная площадь стен по габаритам планировки',
                quantity: $wallArea,
                formula: 'Периметр '.$this->formatNumber($perimeter).' м x высота '.$this->formatNumber($heightM).' м'.$wallOpeningFormulaPart.' = '.$this->formatNumber($wallArea).' м2',
                confidence: max($confidence - 0.06, 0.35),
                sourceRefs: $calculationSourceRefs,
                roomCount: 0,
                reviewRequired: true,
                extraPayload: [
                    ...$dimensionPayload,
                    ...$openingPayload,
                    'height_m' => $heightM,
                    'height_source' => $heightEvidence['source'],
                    'gross_wall_area_m2' => $grossWallArea,
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'paint_area',
                quantityKey: 'finish.paint',
                name: 'Расчетная площадь окраски стен по габаритам планировки',
                quantity: $wallArea,
                formula: 'Периметр '.$this->formatNumber($perimeter).' м x высота '.$this->formatNumber($heightM).' м'.$wallOpeningFormulaPart.' = '.$this->formatNumber($wallArea).' м2',
                confidence: max($confidence - 0.08, 0.35),
                sourceRefs: $calculationSourceRefs,
                roomCount: 0,
                reviewRequired: true,
                extraPayload: [
                    ...$dimensionPayload,
                    ...$openingPayload,
                    'height_m' => $heightM,
                    'height_source' => $heightEvidence['source'],
                    'gross_wall_area_m2' => $grossWallArea,
                ]
            ),
            $this->aggregateTakeoff(
                scopeKey: 'skirting_length',
                quantityKey: 'finish.baseboard',
                name: 'Расчетная длина плинтуса по габаритам планировки',
                quantity: $baseboardLength,
                formula: 'Периметр по габаритам '.$this->formatNumber($perimeter).' м'.$baseboardOpeningFormulaPart.' = '.$this->formatNumber($baseboardLength).' м',
                confidence: max($confidence - 0.1, 0.35),
                sourceRefs: $sourceRefs,
                roomCount: 0,
                reviewRequired: true,
                unit: 'м',
                extraPayload: [
                    ...$dimensionPayload,
                    ...$openingPayload,
                    'gross_baseboard_length_m' => $perimeter,
                ]
            ),
        ];
    }

    private function hasFloorPlanTextSignal(string $filename, OcrRecognitionResult $recognition): bool
    {
        $text = mb_strtolower($filename.' '.implode(' ', array_map(
            static fn (OcrPageResult $page): string => $page->text,
            $recognition->pages
        )));

        return preg_match('/план|планировка|этаж|floor\s*plan|layout|ар\b|архитектур/u', $text) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{length_m: float, width_m: float, confidence: float, source_refs: array<int, array<string, mixed>>}|null
     */
    private function bestFootprintDimensionPair(array $elements): ?array
    {
        $best = null;

        foreach ($this->dimensionElementsForRooms($elements) as $element) {
            $dimension = $this->dimensionPairMeters($element);

            if ($dimension === null) {
                continue;
            }

            $area = (float) $dimension['length_m'] * (float) $dimension['width_m'];

            if ($area < 20.0 || $area > 2500.0) {
                continue;
            }

            if ($best === null || $area > $best['area']) {
                $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
                $best = [
                    ...$dimension,
                    'area' => $area,
                    'confidence' => (float) ($element['confidence'] ?? 0.7),
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
            'confidence' => round((float) $best['confidence'], 4),
            'source_refs' => $best['source_refs'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array{opening_area_m2: float, door_width_m: float, opening_count: int, source_refs: array<int, array<string, mixed>>}
     */
    private function openingAdjustment(array $elements): array
    {
        $openingArea = 0.0;
        $doorWidth = 0.0;
        $openingCount = 0;
        $sourceRefs = [];

        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'opening') {
                continue;
            }

            $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
            $widthM = $this->openingSizeMeters($payload['width_mm'] ?? null);
            $heightM = $this->openingSizeMeters($payload['height_mm'] ?? null);
            $count = max((int) ($payload['count'] ?? $element['value_number'] ?? 1), 1);

            if ($widthM === null || $heightM === null) {
                continue;
            }

            $openingArea += $widthM * $heightM * $count;
            $openingCount += $count;

            if (($payload['quantity_key'] ?? null) === 'openings.doors') {
                $doorWidth += $widthM * $count;
            }

            if (is_array($element['source_ref'] ?? null)) {
                $sourceRefs[] = $element['source_ref'];
            }
        }

        return [
            'opening_area_m2' => round($openingArea, 2),
            'door_width_m' => round($doorWidth, 2),
            'opening_count' => $openingCount,
            'source_refs' => array_slice($sourceRefs, 0, 20),
        ];
    }

    private function openingSizeMeters(mixed $value): ?float
    {
        $number = $this->numericValue($value);

        if ($number === null || $number <= 0) {
            return null;
        }

        return round($number > 20 ? $number / 1000 : $number, 3);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceRefs
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
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<int, array<string, mixed>>
     */
    private function dimensionElementsForRooms(array $elements): array
    {
        return array_values(array_filter(
            $elements,
            fn (array $element): bool => ($element['type'] ?? null) === 'dimension'
                && ($this->dimensionPairMeters($element) !== null || $this->linearDimensionMeters($element) !== null)
                && ! $this->isOpeningDimensionElement($element)
                && ! $this->isMaterialSpecificationDimensionElement($element)
        ));
    }

    private function isMaterialSpecificationDimensionElement(array $element): bool
    {
        $payload = is_array($element['normalized_payload'] ?? null) ? $element['normalized_payload'] : [];
        $sourceRef = is_array($element['source_ref'] ?? null) ? $element['source_ref'] : [];
        $text = mb_strtolower(trim(implode(' ', array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            [
                $element['label'] ?? '',
                $element['value_text'] ?? '',
                $payload['line'] ?? '',
                $sourceRef['excerpt'] ?? '',
            ],
        )))));

        if ($text === '') {
            return false;
        }

        return preg_match('/(?:гост|ту\s|труба|пластин|болт|гайк|шайб|арматур|профнастил|самонарез|фиксатор|панель\s+ограждения|стойка|прогон|балка|лист|шаг\s*\d|l\s*=|∅|ø)/iu', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $roomElement
     * @param  array<int, array<string, mixed>>  $dimensionElements
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromLabelElement(array $roomElement, array $dimensionElements): ?array
    {
        $roomSourceRef = is_array($roomElement['source_ref'] ?? null) ? $roomElement['source_ref'] : [];
        $roomBbox = $this->normalizedBbox($roomElement['bbox'] ?? $roomSourceRef['bbox'] ?? null);

        if ($roomBbox === null) {
            return null;
        }

        $roomPage = $this->nullableInt($roomSourceRef['page_number'] ?? $roomElement['page_number'] ?? null);
        $dimensionPairGeometry = $this->roomGeometryFromDimensionPairs($dimensionElements, null, $roomBbox, $roomPage);

        if ($dimensionPairGeometry !== null) {
            return $dimensionPairGeometry;
        }

        return $this->roomGeometryFromOrthogonalDimensions($dimensionElements, null, $roomBbox, $roomPage);
    }

    /**
     * @param  array<string, mixed>  $roomTakeoff
     * @param  array<int, array<string, mixed>>  $dimensionElements
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
     * @param  array<int, array<string, mixed>>  $dimensionElements
     * @param  array<string, float>  $roomBbox
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromDimensionPairs(array $dimensionElements, ?float $area, array $roomBbox, ?int $roomPage): ?array
    {
        $best = null;

        foreach ($dimensionElements as $element) {
            $dimension = $this->dimensionPairMeters($element);

            if ($dimension === null || ! $this->isRoomDimensionCandidatePlausible($area, $dimension['length_m'], $dimension['width_m'])) {
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
            $areaPenalty = $area !== null
                ? abs($dimensionArea - $area) / max($area, 0.01)
                : min($dimensionArea / 120.0, 1.0) * 0.15;
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
     * @param  array<int, array<string, mixed>>  $dimensionElements
     * @param  array<string, float>  $roomBbox
     * @return array{length_m: float, width_m: float, perimeter_m: float, basis: string, source_refs: array<int, array<string, mixed>>}|null
     */
    private function roomGeometryFromOrthogonalDimensions(array $dimensionElements, ?float $area, array $roomBbox, ?int $roomPage): ?array
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

                if (! $this->isRoomDimensionCandidatePlausible($area, $lengthM, $widthM)) {
                    continue;
                }

                $dimensionArea = $lengthM * $widthM;
                $areaPenalty = $area !== null
                    ? abs($dimensionArea - $area) / max($area, 0.01)
                    : min($dimensionArea / 120.0, 1.0) * 0.15;
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
        if (! is_array($sourceRefs)) {
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
     * @param  array<string, mixed>  $element
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
     * @param  array<string, float>  $bbox
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

    private function isRoomDimensionCandidatePlausible(?float $area, float $lengthM, float $widthM): bool
    {
        $dimensionArea = $lengthM * $widthM;

        if ($area !== null) {
            return $dimensionArea >= $area * 0.6 && $dimensionArea <= $area * 1.8;
        }

        return $dimensionArea >= 1.0
            && $dimensionArea <= 150.0
            && max($lengthM, $widthM) <= 20.0;
    }

    /**
     * @param  array<string, mixed>  $element
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
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
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
     * @param  array<string, float>  $roomBbox
     * @param  array<string, float>  $dimensionBbox
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
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
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
     * @param  array<int, array<string, mixed>>  $elements
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
     * @param  array<int, OcrPageResult>  $pages
     * @param  array<int, array<string, mixed>>  $elements
     * @param  array<int, array<string, mixed>>  $takeoffs
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
            $axisCount = count(array_filter(
                $pageElements,
                static fn (array $element): bool => ($element['type'] ?? null) === 'axis'
            ));
            $heightCount = count(array_filter(
                $pageElements,
                static fn (array $element): bool => ($element['type'] ?? null) === 'height'
            ));
            $titleBlockCount = count(array_filter(
                $pageElements,
                static fn (array $element): bool => ($element['type'] ?? null) === 'title_block'
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
            $text = mb_strtolower($page->text.' '.$filename);
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
                    $titleBlockCount > 0 ? 'title_block' : null,
                    $roomCount > 0 ? 'room_areas' : null,
                    $dimensionCount > 0 ? 'dimensions' : null,
                    $axisCount > 0 ? 'axes' : null,
                    $heightCount > 0 ? 'height' : null,
                    $workVolumeStatementCount > 0 ? 'work_volume_statement_quantities' : null,
                    $specificationCount > 0 ? 'specification_quantities' : null,
                ])),
                'room_area_count' => $roomCount,
                'dimension_count' => $dimensionCount,
                'axis_count' => $axisCount,
                'height_count' => $heightCount,
                'title_block_count' => $titleBlockCount,
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
     * @param  array<int, array<string, mixed>>  $elements
     * @param  array<int, array<string, mixed>>  $takeoffs
     * @param  array<int, array<string, mixed>>  $roomTakeoffs
     * @param  array<int, array<string, mixed>>  $pageProfiles
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
        $axisCount = count(array_filter(
            $elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'axis'
        ));
        $heightCount = count(array_filter(
            $elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'height'
        ));
        $heightEvidence = $this->heightEvidence($elements);
        $detectedHeight = $heightEvidence['source'] === 'drawing_height' ? $heightEvidence['height_m'] : null;
        $titleBlockCount = count(array_filter(
            $elements,
            static fn (array $element): bool => ($element['type'] ?? null) === 'title_block'
        ));
        $documentProfile = $this->documentProfile($filename, $pageProfiles, count($roomTakeoffs), $dimensionCount, $axisCount, $titleBlockCount);

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
            'axis_count' => $axisCount,
            'height_count' => $heightCount,
            'title_block_count' => $titleBlockCount,
            'detected_height_m' => $detectedHeight,
            'evidence_graph' => $this->evidenceGraph($takeoffs),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $takeoffs
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
                'id' => 'takeoff-'.($index + 1),
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
     * @param  array<int, array<string, mixed>>  $pageProfiles
     * @return array<string, mixed>
     */
    private function documentProfile(string $filename, array $pageProfiles, int $roomCount, int $dimensionCount, int $axisCount, int $titleBlockCount): array
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
                'axis_count' => $axisCount,
                'title_block_count' => $titleBlockCount,
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
            str_contains($normalized, 'окно')
                || str_contains($normalized, 'ок-')
                || preg_match('/(?:^|[^\p{L}\p{N}])ок\s*-?\s*\d/iu', $normalized) === 1 => 'openings.windows',
            str_contains($normalized, 'ворота') => 'openings.gates',
            default => 'openings.doors',
        };
    }

    /**
     * @param  array<int, string>  $needles
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
