<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class VisualQuantityParser
{
    private const MAX_VALUE_CHARACTERS = 256;

    private const MAX_COUNT = 9999;

    private const COUNT_MARKERS = [
        'шт', 'штука', 'штуки', 'штук', 'ед', 'pcs', 'pc', 'unit', 'units',
    ];

    private const BLOCKED_SEMANTICS = [
        'артикул', 'арт', 'article', 'sku', 'ось', 'оси', 'axis', 'axes',
        'высота', 'height', 'ширина', 'width', 'длина', 'length', 'глубина', 'depth',
        'площадь', 'area', 'объем', 'объём', 'volume', 'диаметр', 'diameter',
        'отметка', 'elevation', 'уклон', 'slope', 'этаж', 'floor', 'уровень', 'level',
        'год', 'года', 'year', 'марка', 'brand', 'модель', 'model', 'типоразмер', 'size',
    ];

    private const MEASUREMENT_UNITS = [
        'мм', 'см', 'дм', 'м', 'км', 'mm', 'cm', 'dm', 'm', 'km',
        'м2', 'м²', 'm2', 'm²', 'м3', 'м³', 'm3', 'm³',
        'л', 'литр', 'литра', 'литров', 'l', 'kg', 'кг', 'г', 'mm2', 'mm3',
    ];

    private const NOUN_FAMILIES = [
        'sink' => ['мойка', 'мойки', 'моек', 'раковина', 'раковины', 'раковин', 'умывальник', 'умывальника', 'умывальники', 'умывальников', 'sink', 'sinks', 'basin', 'basins', 'washbasin', 'washbasins'],
        'toilet' => ['унитаз', 'унитаза', 'унитазы', 'унитазов', 'toilet', 'toilets'],
        'window' => ['окно', 'окна', 'окон', 'window', 'windows'],
        'door' => ['дверь', 'двери', 'дверей', 'door', 'doors'],
        'bed' => ['кровать', 'кровати', 'кроватей', 'bed', 'beds'],
        'table' => ['стол', 'стола', 'столы', 'столов', 'table', 'tables'],
        'chair' => ['стул', 'стула', 'стулья', 'стульев', 'chair', 'chairs'],
        'sofa' => ['диван', 'дивана', 'диваны', 'диванов', 'sofa', 'sofas'],
    ];

    public function parse(string $value, string $entityKey, string $objectType): VisualQuantityParseResult
    {
        if (mb_strlen($value) > self::MAX_VALUE_CHARACTERS) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '' || preg_match('/\p{Nd}/u', $normalized) !== 1) {
            return new VisualQuantityParseResult('not_count', null, 'not_count');
        }
        $withoutAsciiDigits = preg_replace('/[0-9]/', '', $normalized);
        if ((is_string($withoutAsciiDigits) && preg_match('/\p{Nd}/u', $withoutAsciiDigits) === 1)
            || preg_match('/[0-9]+[.,][0-9]+/u', $normalized) === 1
            || preg_match('/(?<![\p{L}\p{N}])[+\-−][0-9]+/u', $normalized) === 1) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }

        preg_match_all('/(?<![\p{L}\p{N}])([0-9]+)(?![\p{L}\p{N}])/u', $normalized, $numberMatches);
        $numbers = $numberMatches[1] ?? [];
        if (count($numbers) !== 1) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }
        $numericToken = $numbers[0];
        if (! is_string($numericToken) || strlen($numericToken) > 4) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }

        $words = $this->words($normalized);
        if ($this->containsAny($words, self::BLOCKED_SEMANTICS)
            || $this->containsAny($words, self::MEASUREMENT_UNITS)
            || str_contains($normalized, '%')
            || str_contains($normalized, 'ø')
            || str_contains($normalized, '⌀')) {
            return new VisualQuantityParseResult('not_count', null, 'not_count');
        }

        $families = $this->families($words);
        if (count($families) > 1) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }
        $hasCountMarker = $this->containsAny($words, self::COUNT_MARKERS);
        $expectedFamily = $this->expectedFamily($entityKey, $objectType);
        $observedFamily = $families[0] ?? null;
        if ($observedFamily === null && (! $hasCountMarker || $expectedFamily === null)) {
            return new VisualQuantityParseResult('not_count', null, 'not_count');
        }
        if ($observedFamily !== null && $expectedFamily !== null && $observedFamily !== $expectedFamily) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }

        $quantity = (int) $numericToken;
        if ($quantity < 1 || $quantity > self::MAX_COUNT) {
            return new VisualQuantityParseResult('ambiguous', null, 'ambiguous');
        }

        return new VisualQuantityParseResult('count', $quantity, 'explicit_count');
    }

    /** @return list<string> */
    private function words(string $value): array
    {
        preg_match_all('/[\p{L}]+[0-9]?|[0-9]+/u', $value, $matches);

        return array_values(array_filter($matches[0] ?? [], 'is_string'));
    }

    /** @param list<string> $words @param list<string> $needles */
    private function containsAny(array $words, array $needles): bool
    {
        return array_intersect($words, $needles) !== [];
    }

    /** @param list<string> $words @return list<string> */
    private function families(array $words): array
    {
        $families = [];
        foreach (self::NOUN_FAMILIES as $family => $nouns) {
            if ($this->containsAny($words, $nouns)) {
                $families[] = $family;
            }
        }

        return $families;
    }

    private function expectedFamily(string $entityKey, string $objectType): ?string
    {
        $byType = match ($objectType) {
            'kitchen_sink', 'washbasin' => 'sink',
            'toilet' => 'toilet',
            'bed', 'table', 'chair', 'sofa' => $objectType,
            default => null,
        };
        if ($byType !== null) {
            return $byType;
        }

        $words = $this->words(mb_strtolower($entityKey));
        $families = $this->families($words);

        return count($families) === 1 ? $families[0] : null;
    }
}
