<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class BuildingQuantityCalculator
{
    /** @var array<int, array{code: string, severity: string, path: string}> */
    private array $diagnostics = [];

    /** @param array<string, mixed> $model */
    public function calculate(array $model): QuantityCalculationResult
    {
        $this->diagnostics = [];
        $modelVersion = (string) ($model['model_version'] ?? 'unknown');
        $items = [];
        $scaleConfirmed = ($model['scale']['status'] ?? null) === 'confirmed';

        $roomItems = [];
        $seenGeometry = [];
        foreach ($this->records($model, 'rooms') as $index => $room) {
            $area = $this->decimal($room['area'] ?? null);
            if ($area === null && isset($room['polygon'])) {
                if (($room['coordinate_unit'] ?? 'm') !== 'm' || ! $scaleConfirmed) {
                    $this->diagnostic('unconfirmed_scale', "rooms.$index.polygon");

                    continue;
                }
                $area = $this->polygonArea($room['polygon'], "rooms.$index.polygon");
            }
            if ($area === null) {
                continue;
            }
            $identity = isset($room['polygon']) ? $this->canonicalJson($room['polygon']) : null;
            if ($identity !== null && isset($seenGeometry[$identity])) {
                $this->diagnostic('duplicate_room_geometry', "rooms.$index", 'warning');

                continue;
            }
            if ($identity !== null) {
                $seenGeometry[$identity] = true;
            }
            $roomItems[] = $this->item($area, $room);
        }
        if ($roomItems !== []) {
            $items['floor_area'] = $this->aggregate('floor_area', $roomItems, $modelVersion);
            $items['ceiling_area'] = $this->aggregate('ceiling_area', $roomItems, $modelVersion);
        }

        $openings = [];
        $openingItems = [];
        foreach ($this->records($model, 'openings') as $index => $opening) {
            $id = (string) ($opening['id'] ?? '');
            if ($id === '' || isset($openings[$id])) {
                $this->diagnostic('duplicate_opening_key', "openings.$index");

                continue;
            }
            $width = $this->decimal($opening['width'] ?? null);
            $height = $this->decimal($opening['height'] ?? null);
            if ($width === null || $height === null) {
                $this->diagnostic('insufficient_opening_geometry', "openings.$index");

                continue;
            }
            $openings[$id] = $opening + ['_area' => $width->multipliedBy($height)];
            $openingItems[] = $this->item($width->multipliedBy($height), $opening);
        }
        if ($openingItems !== []) {
            $items['opening_area'] = $this->aggregate('opening_area', $openingItems, $modelVersion);
        }

        $grossItems = [];
        $netItems = [];
        foreach ($this->records($model, 'walls') as $index => $wall) {
            $length = $this->decimal($wall['length'] ?? null);
            $height = $this->decimal($wall['height'] ?? null);
            if ($length === null) {
                $this->diagnostic('missing_wall_length', "walls.$index");

                continue;
            }
            if ($height === null) {
                $this->diagnostic('missing_wall_height', "walls.$index");

                continue;
            }
            $gross = $length->multipliedBy($height);
            $grossItems[] = $this->item($gross, $wall);
            $net = $gross;
            $netEvidence = $this->strings($wall['evidence_ids'] ?? []);
            $netSource = $this->source($wall);
            $netAssumptions = $this->strings($wall['assumptions'] ?? []);
            $refs = $this->strings($wall['opening_ids'] ?? []);
            if (count($refs) !== count(array_unique($refs))) {
                $this->diagnostic('duplicate_opening_reference', "walls.$index.opening_ids");
            }
            $valid = true;
            foreach ($refs as $ref) {
                $opening = $openings[$ref] ?? null;
                if ($opening === null || (string) ($opening['wall_id'] ?? '') !== (string) ($wall['id'] ?? '')) {
                    $this->diagnostic('opening_wall_reference_conflict', "walls.$index.opening_ids");
                    $valid = false;

                    continue;
                }
                $net = $net->minus($opening['_area']);
                $netEvidence = [...$netEvidence, ...$this->strings($opening['evidence_ids'] ?? [])];
                $netAssumptions = [...$netAssumptions, ...$this->strings($opening['assumptions'] ?? [])];
                if ($this->source($opening) === QuantitySource::Estimated) {
                    $netSource = QuantitySource::Estimated;
                }
            }
            if (! $valid) {
                continue;
            }
            if ($net->isNegative()) {
                $this->diagnostic('opening_area_exceeds_wall_area', "walls.$index.opening_ids");

                continue;
            }
            $netItems[] = ['amount' => $net, 'source' => $netSource, 'evidence' => $netEvidence, 'assumptions' => $netAssumptions];
        }
        if ($grossItems !== []) {
            $items['gross_wall_area'] = $this->aggregate('gross_wall_area', $grossItems, $modelVersion);
        }
        if ($netItems !== []) {
            $items['net_wall_area'] = $this->aggregate('net_wall_area', $netItems, $modelVersion);
        }

        $foundationItems = [];
        foreach ($this->records($model, 'foundations') as $index => $foundation) {
            $values = array_map(fn (string $key): ?BigDecimal => $this->decimal($foundation[$key] ?? null), ['length', 'width', 'depth']);
            if (in_array(null, $values, true)) {
                $this->diagnostic('insufficient_foundation_geometry', "foundations.$index");

                continue;
            }
            $foundationItems[] = $this->item($values[0]->multipliedBy($values[1])->multipliedBy($values[2]), $foundation);
        }
        if ($foundationItems !== []) {
            $items['foundation_volume'] = $this->aggregate('foundation_volume', $foundationItems, $modelVersion);
        }

        $roofItems = [];
        foreach ($this->records($model, 'roofs') as $index => $roof) {
            $area = $this->decimal($roof['area'] ?? null);
            if ($area === null) {
                $this->diagnostic('insufficient_roof_geometry', "roofs.$index");

                continue;
            }
            $roofItems[] = $this->item($area, $roof);
        }
        if ($roofItems !== []) {
            $items['roof_area'] = $this->aggregate('roof_area', $roofItems, $modelVersion);
        }

        $engineeringGroups = [];
        foreach ($this->records($model, 'engineering') as $index => $element) {
            $system = trim((string) ($element['system'] ?? ''));
            $measurement = trim((string) ($element['measurement'] ?? ''));
            $unit = trim((string) ($element['unit'] ?? ''));
            $amount = $this->decimal($element['amount'] ?? null);
            if ($system === '' || $measurement === '' || $unit === '' || $amount === null) {
                $this->diagnostic('insufficient_engineering_measurement', "engineering.$index");

                continue;
            }
            $key = 'engineering.'.$system.'.'.$measurement;
            if (isset($engineeringGroups[$key]) && $engineeringGroups[$key]['unit'] !== $unit) {
                $this->diagnostic('engineering_unit_conflict', "engineering.$index.unit");

                continue;
            }
            $engineeringGroups[$key]['unit'] = $unit;
            $engineeringGroups[$key]['items'][] = $this->item($amount, $element);
        }
        foreach ($engineeringGroups as $key => $group) {
            $items[$key] = $this->aggregate($key, $group['items'], $modelVersion, $group['unit']);
        }

        ksort($items);
        usort($this->diagnostics, static fn (array $a, array $b): int => [$a['path'], $a['code']] <=> [$b['path'], $b['code']]);

        return new QuantityCalculationResult($items, $this->diagnostics);
    }

    /** @param array<int, array{amount: BigDecimal, source: QuantitySource, evidence: array<int, string>, assumptions: array<int, string>}> $items */
    private function aggregate(string $key, array $items, string $modelVersion, ?string $unit = null): QuantityData
    {
        usort($items, static fn (array $a, array $b): int => [(string) $a['amount'], implode('|', $a['evidence'])] <=> [(string) $b['amount'], implode('|', $b['evidence'])]);
        $sum = BigDecimal::zero();
        $source = QuantitySource::Evidenced;
        $evidence = [];
        $assumptions = [];
        foreach ($items as $item) {
            $sum = $sum->plus($item['amount']);
            $evidence = [...$evidence, ...$item['evidence']];
            $assumptions = [...$assumptions, ...$item['assumptions']];
            if ($item['source'] === QuantitySource::Estimated) {
                $source = QuantitySource::Estimated;
            }
        }
        $catalog = (new QuantityFormulaCatalog)->definition($key);
        $evidence = $this->uniqueSorted($evidence);
        $assumptions = $this->uniqueSorted($assumptions);

        return new QuantityData(
            key: $key, unit: $unit ?? $catalog['unit'], amount: (string) $sum->toScale(6, RoundingMode::HalfUp),
            formulaKey: $catalog['formula'], formulaVersion: QuantityFormulaCatalog::VERSION,
            formulaInputs: ['item_count' => (string) count($items)], source: $source,
            evidenceIds: $evidence, modelVersion: $modelVersion, assumptions: $assumptions,
            reviewBlockers: $source === QuantitySource::Estimated ? ['estimated_quantity_requires_review'] : [],
        );
    }

    /** @param array<string, mixed> $record @return array{amount: BigDecimal, source: QuantitySource, evidence: array<int, string>, assumptions: array<int, string>} */
    private function item(BigDecimal $amount, array $record): array
    {
        return ['amount' => $amount, 'source' => $this->source($record), 'evidence' => $this->strings($record['evidence_ids'] ?? []), 'assumptions' => $this->strings($record['assumptions'] ?? [])];
    }

    /** @param array<string, mixed> $record */
    private function source(array $record): QuantitySource
    {
        return ($record['source'] ?? null) === 'estimated' || $this->strings($record['assumptions'] ?? []) !== []
            ? QuantitySource::Estimated : QuantitySource::Evidenced;
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        try {
            $decimal = BigDecimal::of($value);

            return $decimal->isNegative() ? null : $decimal;
        } catch (\Throwable) {
            return null;
        }
    }

    private function polygonArea(mixed $polygon, string $path): ?BigDecimal
    {
        if (! is_array($polygon) || count($polygon) < 3) {
            $this->diagnostic('degenerate_polygon', $path);

            return null;
        }
        $sum = BigDecimal::zero();
        $points = array_values($polygon);
        foreach ($points as $index => $point) {
            $next = $points[($index + 1) % count($points)];
            if (! is_array($point) || ! is_array($next) || count($point) !== 2 || count($next) !== 2) {
                $this->diagnostic('invalid_polygon_coordinate', $path);

                return null;
            }
            $x = $this->decimal($point[0]);
            $y = $this->decimal($point[1]);
            $nextX = $this->decimal($next[0]);
            $nextY = $this->decimal($next[1]);
            if ($x === null || $y === null || $nextX === null || $nextY === null) {
                $this->diagnostic('invalid_polygon_coordinate', $path);

                return null;
            }
            $sum = $sum->plus($x->multipliedBy($nextY))->minus($nextX->multipliedBy($y));
        }
        $area = $sum->abs()->dividedBy('2', $sum->getScale(), RoundingMode::Unnecessary);
        if ($area->isZero()) {
            $this->diagnostic('degenerate_polygon', $path);

            return null;
        }

        return $area;
    }

    /** @param array<string, mixed> $model @return array<int, array<string, mixed>> */
    private function records(array $model, string $key): array
    {
        return array_values(array_filter(is_array($model[$key] ?? null) ? $model[$key] : [], 'is_array'));
    }

    /** @return array<int, string> */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $values))) : [];
    }

    /** @param array<int, string> $values @return array<int, string> */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private function diagnostic(string $code, string $path, string $severity = 'blocking'): void
    {
        $this->diagnostics[] = compact('code', 'severity', 'path');
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
