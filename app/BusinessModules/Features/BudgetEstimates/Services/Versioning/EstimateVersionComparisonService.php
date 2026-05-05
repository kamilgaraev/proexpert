<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Models\EstimateVersion;
use InvalidArgumentException;

class EstimateVersionComparisonService
{
    private const WATCH_FIELDS = [
        'position_number',
        'name',
        'quantity',
        'quantity_total',
        'unit',
        'unit_price',
        'current_unit_price',
        'total_amount',
        'current_total_amount',
        'direct_costs',
        'materials_cost',
        'machinery_cost',
        'labor_cost',
        'equipment_cost',
        'overhead_amount',
        'profit_amount',
    ];

    private const QUANTITY_FIELDS = [
        'quantity',
        'quantity_total',
    ];

    private const STRING_FIELDS = [
        'position_number',
        'name',
        'unit',
    ];

    public function compare(EstimateVersion $versionA, EstimateVersion $versionB): array
    {
        if ((int) $versionA->estimate_id !== (int) $versionB->estimate_id) {
            throw new InvalidArgumentException('Нельзя сравнивать версии разных смет');
        }

        $itemsA = $this->indexItems($this->flattenSnapshotItems($versionA->snapshot ?? []));
        $itemsB = $this->indexItems($this->flattenSnapshotItems($versionB->snapshot ?? []));

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        foreach ($itemsB as $key => $entryB) {
            if (!array_key_exists($key, $itemsA)) {
                $added[] = $this->diffItem($entryB, 'added');
                continue;
            }

            $entryA = $itemsA[$key];
            $changes = $this->itemChanges($entryA['item'], $entryB['item']);

            if ($changes === []) {
                $unchanged++;
                continue;
            }

            $changed[] = [
                ...$this->diffItem($entryB, 'changed'),
                'before' => $entryA['item'],
                'after' => $entryB['item'],
                'changes' => $changes,
            ];
        }

        foreach ($itemsA as $key => $entryA) {
            if (!array_key_exists($key, $itemsB)) {
                $removed[] = $this->diffItem($entryA, 'removed');
            }
        }

        $totalDeltaAmount = $this->numericTotalAmount($versionB) - $this->numericTotalAmount($versionA);

        return [
            'version_a' => $this->versionPayload($versionA),
            'version_b' => $this->versionPayload($versionB),
            'summary' => [
                'added' => count($added),
                'removed' => count($removed),
                'changed' => count($changed),
                'unchanged' => $unchanged,
                'total_delta_amount' => $this->money($totalDeltaAmount),
                'total_delta_pct' => $this->deltaPct($this->numericTotalAmount($versionA), $totalDeltaAmount),
            ],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    private function versionPayload(EstimateVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'total_amount' => $this->money($this->numericTotalAmount($version)),
        ];
    }

    private function flattenSnapshotItems(array $snapshot): array
    {
        return [
            ...$this->flattenItems($snapshot['unsectioned_items'] ?? [], 'unsectioned'),
            ...$this->flattenSections($snapshot['sections'] ?? [], 'sections'),
        ];
    }

    private function flattenSections(array $sections, string $path): array
    {
        $items = [];

        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionPath = $path . '.' . $index;

            $items = [
                ...$items,
                ...$this->flattenItems($section['items'] ?? [], $sectionPath . '.items'),
                ...$this->flattenSections($section['children'] ?? [], $sectionPath . '.children'),
            ];
        }

        return $items;
    }

    private function flattenItems(array $items, string $path): array
    {
        $flattened = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemPath = $path . '.' . $index;
            $flattened[] = [
                'item' => $item,
                'ordinal_path' => $itemPath,
            ];
            $flattened = [
                ...$flattened,
                ...$this->flattenItems($item['children'] ?? [], $itemPath . '.children'),
            ];
        }

        return $flattened;
    }

    private function indexItems(array $items): array
    {
        $indexed = [];
        $occurrences = [];

        foreach ($items as $item) {
            $entry = $this->itemEntry($item['item'], $item['ordinal_path']);
            $baseKey = $entry['key'];
            $occurrences[$baseKey] = ($occurrences[$baseKey] ?? 0) + 1;
            $key = $occurrences[$baseKey] === 1
                ? $baseKey
                : $baseKey . ':duplicate:' . $occurrences[$baseKey];

            $indexed[$key] = [
                ...$entry,
                'key' => $key,
                'stable_key' => $key,
            ];
        }

        return $indexed;
    }

    private function itemEntry(array $item, string $ordinalPath): array
    {
        $stableKey = $this->filledString($item['stable_key'] ?? null);

        if ($stableKey !== null) {
            return [
                'key' => $stableKey,
                'match_key_type' => 'stable_key',
                'item' => $item,
            ];
        }

        $structuralKey = $this->filledString($item['structural_key'] ?? null);

        if ($structuralKey !== null) {
            return [
                'key' => $structuralKey,
                'match_key_type' => 'structural_key',
                'item' => $item,
            ];
        }

        $legacyId = $this->filledScalar($item['id'] ?? null);

        if ($legacyId !== null) {
            return [
                'key' => 'legacy-id:' . $legacyId,
                'match_key_type' => 'legacy_id',
                'item' => $item,
            ];
        }

        return [
            'key' => 'ordinal-path:' . $ordinalPath,
            'match_key_type' => 'fallback',
            'item' => $item,
        ];
    }

    private function diffItem(array $entry, string $diffType): array
    {
        return [
            ...$entry['item'],
            'stable_key' => $entry['stable_key'],
            'match_key_type' => $entry['match_key_type'],
            'diff_type' => $diffType,
            '_diff' => $diffType,
        ];
    }

    private function itemChanges(array $itemA, array $itemB): array
    {
        $changes = [];

        foreach (self::WATCH_FIELDS as $field) {
            $valueA = $this->fieldValue($itemA, $field);
            $valueB = $this->fieldValue($itemB, $field);

            if (in_array($field, self::STRING_FIELDS, true)) {
                if ($valueA !== $valueB) {
                    $changes[$field] = [
                        'before' => $valueA,
                        'after' => $valueB,
                    ];
                }

                continue;
            }

            $before = $this->numericValue($valueA);
            $after = $this->numericValue($valueB);
            $precision = in_array($field, self::QUANTITY_FIELDS, true) ? 8 : 2;
            $formattedBefore = $this->formatNumeric($before, $precision);
            $formattedAfter = $this->formatNumeric($after, $precision);

            if ($formattedBefore === $formattedAfter) {
                continue;
            }

            $changes[$field] = [
                'before' => $formattedBefore,
                'after' => $formattedAfter,
                'delta' => $before !== null && $after !== null
                    ? $this->formatNumeric($after - $before, $precision)
                    : null,
            ];
        }

        return $changes;
    }

    private function fieldValue(array $item, string $field): mixed
    {
        if ($field !== 'unit') {
            return $item[$field] ?? null;
        }

        if (array_key_exists('unit', $item)) {
            return $item['unit'];
        }

        $measurementUnit = $item['measurement_unit'] ?? null;

        if (is_array($measurementUnit)) {
            return $measurementUnit['short_name'] ?? $measurementUnit['name'] ?? null;
        }

        return null;
    }

    private function filledString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function filledScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericTotalAmount(EstimateVersion $version): float
    {
        $snapshotTotal = $version->snapshot['totals']['total_amount'] ?? null;

        if (is_numeric($snapshotTotal)) {
            return (float) $snapshotTotal;
        }

        return (float) ($version->total_amount ?? 0);
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function formatNumeric(?float $value, int $precision): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, $precision, '.', '');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function deltaPct(float $baseAmount, float $deltaAmount): ?string
    {
        if ($baseAmount == 0.0) {
            return null;
        }

        return number_format($deltaAmount / abs($baseAmount) * 100, 2, '.', '');
    }
}
