<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\ExtractedDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

class ConstructionDocumentFactExtractor
{
    /**
     * @return array<int, ExtractedDocumentFact>
     */
    public function extract(OcrRecognitionResult $recognition, int $documentId, string $filename): array
    {
        $facts = [];

        foreach ($recognition->pages as $page) {
            foreach ($this->normalizedLines($page) as $line) {
                array_push(
                    $facts,
                    ...$this->extractAreaFacts($line, $page, $documentId, $filename),
                    ...$this->extractDimensionFacts($line, $page, $documentId, $filename),
                    ...$this->extractFloorFacts($line, $page, $documentId, $filename),
                    ...$this->extractHeightFacts($line, $page, $documentId, $filename),
                    ...$this->extractEngineeringFacts($line, $page, $documentId, $filename),
                );
            }
        }

        return $this->deduplicate($facts);
    }

    /**
     * @return array<int, string>
     */
    private function lines(OcrPageResult $page): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $page->text) ?: [],
        )));
    }

    /**
     * @return array<int, string>
     */
    private function normalizedLines(OcrPageResult $page): array
    {
        $lines = $this->lines($page);
        $normalized = [];
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            $nextLine = $lines[$index + 1] ?? null;

            if (
                $nextLine !== null
                && preg_match('/\d+(?:[,.]\d+)?\s*м$/iu', $line) === 1
                && preg_match('/^[2²]$/u', $nextLine) === 1
            ) {
                $line .= '2';
                $index++;
            }

            $normalized[] = $line;
        }

        return $normalized;
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractAreaFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all(
            '/(?P<label>[А-ЯA-Zа-яa-z0-9 №\\-]{0,80}?)(?P<value>\d+(?:[,.]\d+)?)\s*(?:м2|м²|кв\.?\s*м)/iu',
            $line,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $lastNonParentheticalIndex = null;
        foreach ($matches as $index => $match) {
            if (!$this->isInsideParentheses($line, (int) $match['value'][1])) {
                $lastNonParentheticalIndex = $index;
            }
        }

        return array_values(array_map(function (array $match, int $index) use ($line, $page, $documentId, $filename, $lastNonParentheticalIndex): ExtractedDocumentFact {
            $rawLabel = trim((string) ($match['label'][0] ?? ''));
            $valueText = (string) $match['value'][0];
            $value = $this->number($valueText);
            $insideParentheses = $this->isInsideParentheses($line, (int) $match['value'][1]);
            $lineHasTotal = $this->hasTotalAreaKeyword($line);
            $isTotal = !$insideParentheses
                && (
                    $this->hasTotalAreaKeyword($rawLabel)
                    || ($lineHasTotal && $index === $lastNonParentheticalIndex)
                );
            $label = $this->areaLabel($rawLabel, $isTotal);
            $areaRole = $this->areaRole($label, $line, $isTotal);

            return new ExtractedDocumentFact(
                factType: $areaRole === 'total' ? 'total_area' : 'zone_area',
                label: $label,
                confidence: $page->confidence ?? 0.7,
                scopeKey: $areaRole === 'total' ? 'total_area' : $this->scopeKey($label),
                valueText: $valueText . ' м2',
                valueNumber: $value,
                unit: 'м2',
                sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
                normalizedPayload: [
                    'area_role' => $areaRole,
                    'source_rank' => $this->areaSourceRank($areaRole),
                    'line' => $line,
                ],
            );
        }, $matches, array_keys($matches)));
    }

    private function hasTotalAreaKeyword(string $value): bool
    {
        return preg_match('/общ|итого|всего|площадь\s+здания|площадь\s+объекта|площадь\s+дома/iu', $value) === 1;
    }

    private function isInsideParentheses(string $line, int $byteOffset): bool
    {
        $before = substr($line, 0, max($byteOffset, 0));

        return substr_count($before, '(') > substr_count($before, ')');
    }

    private function areaLabel(string $label, bool $isTotal): string
    {
        $label = trim((string) preg_replace('/^[\s().,:;–—-]+|[\s().,:;–—-]+$/u', '', $label));

        if ($label !== '') {
            return $label;
        }

        return $isTotal ? 'Общая площадь' : 'Площадь зоны';
    }

    private function areaRole(string $label, string $line, bool $isTotal): string
    {
        if ($isTotal) {
            return 'total';
        }

        $value = mb_strtolower($label . ' ' . $line);

        if (preg_match('/террас/u', $value) === 1) {
            return 'terrace';
        }

        if (preg_match('/жил/u', $value) === 1) {
            return 'living';
        }

        if (preg_match('/комнат|помещен/u', $value) === 1) {
            return 'room';
        }

        if (preg_match('/зон/u', $value) === 1) {
            return 'zone';
        }

        return 'unknown';
    }

    private function areaSourceRank(string $areaRole): int
    {
        return match ($areaRole) {
            'total' => 10,
            'living' => 40,
            'terrace' => 50,
            'zone' => 60,
            'unknown' => 70,
            'room' => 50,
            default => 70,
        };
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractDimensionFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all(
            '/(?P<length>\d+(?:[,.]\d+)?)\s*[xх×]\s*(?P<width>\d+(?:[,.]\d+)?)(?:\s*(?P<unit>мм|см|м)(?![2²3³]))?/iu',
            $line,
            $matches,
            PREG_SET_ORDER
        );

        return array_values(array_map(function (array $match) use ($line, $page, $documentId, $filename): ExtractedDocumentFact {
            $rawLength = $this->number((string) $match['length']);
            $rawWidth = $this->number((string) $match['width']);
            $dimension = $this->normalizeDimension($rawLength, $rawWidth, (string) ($match['unit'] ?? ''));

            return new ExtractedDocumentFact(
                factType: 'dimension',
                label: 'Габарит',
                confidence: $page->confidence ?? 0.7,
                scopeKey: 'dimension',
                valueText: $match['length'] . ' x ' . $match['width'],
                valueNumber: $dimension['area_m2'],
                unit: 'м2',
                sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
                normalizedPayload: [
                    'length' => $dimension['length_m'],
                    'width' => $dimension['width_m'],
                    'length_m' => $dimension['length_m'],
                    'width_m' => $dimension['width_m'],
                    'area_m2' => $dimension['area_m2'],
                    'raw_length' => $rawLength,
                    'raw_width' => $rawWidth,
                    'unit_assumption' => $dimension['unit_assumption'],
                    'line' => $line,
                ],
            );
        }, $matches));
    }

    /**
     * @return array{length_m: float, width_m: float, area_m2: float, unit_assumption: string}
     */
    private function normalizeDimension(float $length, float $width, string $unit): array
    {
        $unitAssumption = $this->dimensionUnitAssumption($length, $width, $unit);
        $factor = match ($unitAssumption) {
            'mm' => 0.001,
            'cm' => 0.01,
            default => 1.0,
        };
        $lengthM = round($length * $factor, 4);
        $widthM = round($width * $factor, 4);

        return [
            'length_m' => $lengthM,
            'width_m' => $widthM,
            'area_m2' => round($lengthM * $widthM, 4),
            'unit_assumption' => $unitAssumption,
        ];
    }

    private function dimensionUnitAssumption(float $length, float $width, string $unit): string
    {
        $unit = mb_strtolower(trim($unit));

        if ($unit === 'мм') {
            return 'mm';
        }

        if ($unit === 'см') {
            return 'cm';
        }

        if ($unit === 'м') {
            return 'm';
        }

        $max = max($length, $width);

        if ($max >= 1000) {
            return 'mm';
        }

        if ($max >= 100) {
            return 'cm';
        }

        return 'm';
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractFloorFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all('/(?P<count>\d+)\s*(?:этаж|этажа|этажей)/iu', $line, $matches, PREG_SET_ORDER);

        return array_values(array_map(fn (array $match): ExtractedDocumentFact => new ExtractedDocumentFact(
            factType: 'floor_count',
            label: 'Этажность',
            confidence: $page->confidence ?? 0.7,
            scopeKey: 'floor_count',
            valueText: $match['count'] . ' эт.',
            valueNumber: (float) $match['count'],
            unit: 'эт.',
            sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
            normalizedPayload: ['line' => $line],
        ), $matches));
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractHeightFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all('/(?:h|высота)\s*[=: -]?\s*(?P<height>\d+(?:[,.]\d+)?)\s*м/iu', $line, $matches, PREG_SET_ORDER);

        return array_values(array_map(fn (array $match): ExtractedDocumentFact => new ExtractedDocumentFact(
            factType: 'height',
            label: 'Высота',
            confidence: $page->confidence ?? 0.7,
            scopeKey: 'height',
            valueText: $match['height'] . ' м',
            valueNumber: $this->number((string) $match['height']),
            unit: 'м',
            sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
            normalizedPayload: ['line' => $line],
        ), $matches));
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractEngineeringFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        $systems = [
            'электроснабжение' => 'electrical',
            'освещение' => 'lighting',
            'водоснабжение' => 'water_supply',
            'канализация' => 'sewerage',
            'отопление' => 'heating',
            'вентиляция' => 'ventilation',
            'пожарная сигнализация' => 'fire_alarm',
        ];

        $facts = [];

        foreach ($systems as $needle => $scopeKey) {
            if (mb_stripos($line, $needle) === false) {
                continue;
            }

            $facts[] = new ExtractedDocumentFact(
                factType: 'engineering_system',
                label: $needle,
                confidence: $page->confidence ?? 0.7,
                scopeKey: $scopeKey,
                valueText: $needle,
                sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
                normalizedPayload: ['line' => $line],
            );
        }

        return $facts;
    }

    /**
     * @param array<int, ExtractedDocumentFact> $facts
     * @return array<int, ExtractedDocumentFact>
     */
    private function deduplicate(array $facts): array
    {
        $unique = [];

        foreach ($facts as $fact) {
            $key = implode('|', [
                $fact->factType,
                $fact->scopeKey,
                $fact->valueText,
                $fact->sourceRef['page_number'] ?? '',
            ]);

            $unique[$key] = $fact;
        }

        return array_values($unique);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceRef(int $documentId, string $filename, OcrPageResult $page, string $line): array
    {
        return [
            'type' => 'document',
            'document_id' => $documentId,
            'filename' => $filename,
            'page_number' => $page->pageNumber,
            'excerpt' => mb_substr($line, 0, 240),
        ];
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }

    private function scopeKey(string $label): string
    {
        $normalized = mb_strtolower(trim($label));
        $normalized = preg_replace('/[^a-zа-я0-9]+/iu', '_', $normalized) ?: 'area';

        return trim($normalized, '_') ?: 'area';
    }
}
