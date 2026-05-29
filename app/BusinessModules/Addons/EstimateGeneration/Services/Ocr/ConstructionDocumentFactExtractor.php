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
            foreach ($this->lines($page) as $line) {
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
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractAreaFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all('/(?P<label>[А-ЯA-Zа-яa-z0-9 №\\-]{0,60}?)(?P<value>\d+(?:[,.]\d+)?)\s*(?:м2|м²|кв\.?\s*м)/iu', $line, $matches, PREG_SET_ORDER);

        return array_values(array_map(function (array $match) use ($line, $page, $documentId, $filename): ExtractedDocumentFact {
            $label = trim((string) ($match['label'] ?? 'Площадь'));
            $value = $this->number((string) $match['value']);
            $isTotal = preg_match('/общ|итого|всего|площадь\s+здания|площадь\s+объекта/iu', $label . ' ' . $line) === 1;

            return new ExtractedDocumentFact(
                factType: $isTotal ? 'total_area' : 'zone_area',
                label: $label !== '' ? $label : ($isTotal ? 'Общая площадь' : 'Площадь зоны'),
                confidence: $page->confidence ?? 0.7,
                scopeKey: $isTotal ? 'total_area' : $this->scopeKey($label),
                valueText: $match['value'] . ' м2',
                valueNumber: $value,
                unit: 'м2',
                sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
                normalizedPayload: ['line' => $line],
            );
        }, $matches));
    }

    /**
     * @return array<int, ExtractedDocumentFact>
     */
    private function extractDimensionFacts(string $line, OcrPageResult $page, int $documentId, string $filename): array
    {
        preg_match_all('/(?P<length>\d+(?:[,.]\d+)?)\s*[xх×]\s*(?P<width>\d+(?:[,.]\d+)?)/iu', $line, $matches, PREG_SET_ORDER);

        return array_values(array_map(fn (array $match): ExtractedDocumentFact => new ExtractedDocumentFact(
            factType: 'dimension',
            label: 'Габарит',
            confidence: $page->confidence ?? 0.7,
            scopeKey: 'dimension',
            valueText: $match['length'] . ' x ' . $match['width'],
            valueNumber: $this->number((string) $match['length']) * $this->number((string) $match['width']),
            unit: 'м2',
            sourceRef: $this->sourceRef($documentId, $filename, $page, $line),
            normalizedPayload: [
                'length' => $this->number((string) $match['length']),
                'width' => $this->number((string) $match['width']),
                'line' => $line,
            ],
        ), $matches));
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
