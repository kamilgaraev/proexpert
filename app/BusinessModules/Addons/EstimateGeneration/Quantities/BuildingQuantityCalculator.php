<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class BuildingQuantityCalculator
{
    /** @var array<int, array{code: string, severity: string, path: string}> */
    private array $diagnostics = [];

    private bool $scaleConfirmed = false;

    /** @param array<string, mixed> $model */
    public function calculate(array $model): QuantityCalculationResult
    {
        $this->diagnostics = [];
        $modelVersion = (string) ($model['model_version'] ?? 'unknown');
        $items = [];
        $recordCount = 0;
        foreach (['rooms', 'walls', 'openings', 'foundations', 'roofs', 'engineering'] as $collection) {
            if (is_array($model[$collection] ?? null)) {
                $recordCount += count($model[$collection]);
                if (count($model[$collection]) > 10_000) {
                    $this->diagnostic('collection_resource_limit_exceeded', $collection);
                }
            }
        }
        if ($recordCount > 5_000) {
            $this->diagnostic('model_record_resource_limit_exceeded', 'model');

            return new QuantityCalculationResult([], $this->diagnostics);
        }
        $scaleConfirmed = ($model['scale']['status'] ?? null) === 'confirmed';
        $this->scaleConfirmed = $scaleConfirmed;
        $wallRecords = $this->records($model, 'walls');
        $wallIndex = [];
        $conflictingWallIds = [];
        $wallGeometryOwners = [];
        foreach ($wallRecords as $index => $wall) {
            $wallId = trim((string) ($wall['id'] ?? ''));
            if ($wallId === '' || isset($wallIndex[$wallId])) {
                $this->diagnostic('duplicate_or_missing_wall_identity', "walls.$index.id");
                if ($wallId !== '') {
                    $conflictingWallIds[$wallId] = true;
                    unset($wallIndex[$wallId]);
                }

                continue;
            }
            if (isset($conflictingWallIds[$wallId])) {
                continue;
            }
            $geometryIdentity = trim((string) ($wall['geometry_identity'] ?? ''));
            if ($geometryIdentity !== '' && isset($wallGeometryOwners[$geometryIdentity])) {
                $previousId = $wallGeometryOwners[$geometryIdentity];
                $conflictingWallIds[$previousId] = true;
                $conflictingWallIds[$wallId] = true;
                unset($wallIndex[$previousId]);
                $this->diagnostic('duplicate_wall_geometry_identity', "walls.$index.geometry_identity");

                continue;
            }
            if ($geometryIdentity !== '') {
                $wallGeometryOwners[$geometryIdentity] = $wallId;
            }
            $wallIndex[$wallId] = $wall;
        }

        $roomItems = [];
        $seenGeometry = [];
        $roomPolygons = [];
        $ambiguousOverlap = false;
        foreach ($this->records($model, 'rooms') as $index => $room) {
            if (isset($room['holes'])) {
                $this->diagnostic('polygon_holes_unsupported', "rooms.$index.holes");

                continue;
            }
            $areaValue = $room['area'] ?? null;
            $areaOperand = $areaValue !== null ? $this->operand($room, 'area', 'm2', $modelVersion, "rooms.$index.area") : null;
            $area = $areaOperand?->value;
            if (! $scaleConfirmed && $area !== null && (! is_array($areaValue) || ($areaValue['metric_independent'] ?? false) !== true)) {
                $this->diagnostic('unconfirmed_scale', "rooms.$index.area");
                $area = null;
            }
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
            $identity = isset($room['polygon']) ? $this->polygonIdentity($room['polygon']) : null;
            if ($identity !== null && isset($seenGeometry[$identity])) {
                $this->diagnostic('duplicate_room_geometry', "rooms.$index", 'warning');

                continue;
            }
            if ($identity !== null) {
                $seenGeometry[$identity] = true;
            }
            if (isset($room['polygon'])) {
                if (is_array($room['polygon'])) {
                    foreach ($roomPolygons as $previous) {
                        if ($this->polygonsProperlyIntersect($room['polygon'], $previous)) {
                            $this->diagnostic('ambiguous_polygon_overlap', "rooms.$index.polygon");
                            $ambiguousOverlap = true;
                        }
                    }
                    $roomPolygons[] = $room['polygon'];
                }
            }
            $roomRecord = $areaOperand === null
                ? $room + ['_operand_contexts' => ['model:'.$modelVersion], '_operand_provenance_versions' => [$modelVersion]]
                : $this->recordFromOperand($room, $areaOperand);
            $roomRecord['_formula_operands'] = ['area' => (string) $area];
            $roomItems[] = $this->item($area, $roomRecord);
        }
        if ($ambiguousOverlap) {
            $roomItems = [];
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
            $wallId = trim((string) ($opening['wall_id'] ?? ''));
            $wall = $wallIndex[$wallId] ?? null;
            if ($wall === null || ! in_array($id, $this->strings($wall['opening_ids'] ?? []), true)) {
                $this->diagnostic('orphan_or_unidirectional_opening_reference', "openings.$index.wall_id");

                continue;
            }
            $width = $this->operand($opening, 'width', 'm', $modelVersion, "openings.$index.width");
            $height = $this->operand($opening, 'height', 'm', $modelVersion, "openings.$index.height");
            if ($width === null || $height === null) {
                $this->diagnostic('insufficient_opening_geometry', "openings.$index");

                continue;
            }
            $openingRecord = $this->recordFromOperands($opening, [$width, $height]);
            $openingRecord['_formula_operands'] = ['width' => (string) $width->value, 'height' => (string) $height->value];
            $openings[$id] = $openingRecord + ['_area' => $width->value->multipliedBy($height->value)];
            $openingItems[] = $this->item($width->value->multipliedBy($height->value), $openingRecord);
        }
        if ($openingItems !== []) {
            $items['opening_area'] = $this->aggregate('opening_area', $openingItems, $modelVersion);
        }

        $grossItems = [];
        $netItems = [];
        foreach ($wallRecords as $index => $wall) {
            if (! isset($wallIndex[(string) ($wall['id'] ?? '')]) || isset($conflictingWallIds[(string) ($wall['id'] ?? '')])) {
                continue;
            }
            if (($wall['shared'] ?? false) === true && ! in_array($wall['side_policy'] ?? null, ['single_face', 'both_faces'], true)) {
                $this->diagnostic('shared_wall_side_policy_missing', "walls.$index.side_policy");

                continue;
            }
            if (! array_key_exists('height', $wall)) {
                $this->diagnostic('missing_wall_height', "walls.$index");

                continue;
            }
            $length = $this->operand($wall, 'length', 'm', $modelVersion, "walls.$index.length");
            $height = $this->operand($wall, 'height', 'm', $modelVersion, "walls.$index.height");
            if ($length === null) {
                $this->diagnostic('missing_wall_length', "walls.$index");

                continue;
            }
            if ($height === null) {
                $this->diagnostic('missing_wall_height', "walls.$index");

                continue;
            }
            $wallRecord = $this->recordFromOperands($wall, [$length, $height]);
            $sideMultiplier = ($wall['shared'] ?? false) === true && ($wall['side_policy'] ?? null) === 'both_faces'
                ? BigDecimal::of('2') : BigDecimal::one();
            $wallRecord['_formula_operands'] = [
                'length' => (string) $length->value, 'height' => (string) $height->value,
                'side_multiplier' => (string) $sideMultiplier,
            ];
            $gross = $length->value->multipliedBy($height->value)->multipliedBy($sideMultiplier);
            $grossItems[] = $this->item($gross, $wallRecord);
            $net = $gross;
            $netEvidence = $this->strings($wallRecord['evidence_ids'] ?? []);
            $netSource = $this->source($wallRecord);
            $netAssumptions = $this->strings($wallRecord['assumptions'] ?? []);
            $netContexts = $this->strings($wallRecord['_operand_contexts'] ?? []);
            $netVersions = $this->strings($wallRecord['_operand_provenance_versions'] ?? []);
            $openingOperands = [];
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
                $openingContexts = $this->strings($opening['_operand_contexts'] ?? []);
                if (count(array_unique([...$netContexts, ...$openingContexts])) > 1) {
                    $this->diagnostic('wall_opening_context_conflict', "walls.$index.opening_ids");
                    $valid = false;

                    continue;
                }
                $net = $net->minus($opening['_area']);
                $openingOperands[$ref] = (string) $opening['_area'];
                $netEvidence = [...$netEvidence, ...$this->strings($opening['evidence_ids'] ?? [])];
                $netAssumptions = [...$netAssumptions, ...$this->strings($opening['assumptions'] ?? [])];
                if ($this->source($opening) === QuantitySource::Estimated) {
                    $netSource = QuantitySource::Estimated;
                }
                $netContexts = [...$netContexts, ...$openingContexts];
                $netVersions = [...$netVersions, ...$this->strings($opening['_operand_provenance_versions'] ?? [])];
            }
            if (! $valid) {
                continue;
            }
            if ($net->isNegative()) {
                $this->diagnostic('opening_area_exceeds_wall_area', "walls.$index.opening_ids");

                continue;
            }
            $netItems[] = [
                'amount' => $net, 'source' => $netSource, 'evidence' => $netEvidence,
                'assumptions' => $netAssumptions, 'identity' => (string) ($wall['id'] ?? "wall-$index"),
                'contexts' => $this->uniqueSorted($netContexts),
                'provenance_versions' => $this->uniqueSorted($netVersions),
                'named_operands' => ['gross_wall_area' => (string) $gross, 'openings' => $openingOperands],
            ];
        }
        if ($grossItems !== []) {
            $items['gross_wall_area'] = $this->aggregate('gross_wall_area', $grossItems, $modelVersion);
        }
        if ($netItems !== []) {
            $items['net_wall_area'] = $this->aggregate('net_wall_area', $netItems, $modelVersion);
        }

        $foundationItems = [];
        foreach ($this->records($model, 'foundations') as $index => $foundation) {
            $operands = array_map(fn (string $key): ?QuantityOperandData => $this->operand($foundation, $key, 'm', $modelVersion, "foundations.$index.$key"), ['length', 'width', 'depth']);
            $values = array_map(static fn (?QuantityOperandData $operand): ?BigDecimal => $operand?->value, $operands);
            if (in_array(null, $values, true)) {
                $this->diagnostic('insufficient_foundation_geometry', "foundations.$index");

                continue;
            }
            $foundationRecord = $this->recordFromOperands($foundation, array_filter($operands));
            $foundationRecord['_formula_operands'] = ['length' => (string) $values[0], 'width' => (string) $values[1], 'depth' => (string) $values[2]];
            $foundationItems[] = $this->item($values[0]->multipliedBy($values[1])->multipliedBy($values[2]), $foundationRecord);
        }
        if ($foundationItems !== []) {
            $items['foundation_volume'] = $this->aggregate('foundation_volume', $foundationItems, $modelVersion);
        }

        $roofItems = [];
        foreach ($this->records($model, 'roofs') as $index => $roof) {
            $area = $this->operand($roof, 'area', 'm2', $modelVersion, "roofs.$index.area");
            if ($area === null) {
                $this->diagnostic('insufficient_roof_geometry', "roofs.$index");

                continue;
            }
            $roofRecord = $this->recordFromOperand($roof, $area);
            $roofRecord['_formula_operands'] = ['area' => (string) $area->value];
            $roofItems[] = $this->item($area->value, $roofRecord);
        }
        if ($roofItems !== []) {
            $items['roof_area'] = $this->aggregate('roof_area', $roofItems, $modelVersion);
        }

        $engineeringGroups = [];
        $allowedEngineering = [
            'water.length.m', 'sewer.length.m', 'heating.length.m', 'ventilation.length.m',
            'electrical.length.m', 'electrical.point.count', 'water.point.count',
        ];
        foreach ($this->records($model, 'engineering') as $index => $element) {
            $system = trim((string) ($element['system'] ?? ''));
            $measurement = trim((string) ($element['measurement'] ?? ''));
            $unit = trim((string) ($element['unit'] ?? ''));
            if ($system === '' || $measurement === '' || $unit === '' || ! array_key_exists('amount', $element)) {
                $this->diagnostic('insufficient_engineering_measurement', "engineering.$index");

                continue;
            }
            if (! in_array($system.'.'.$measurement.'.'.$unit, $allowedEngineering, true)) {
                $this->diagnostic('unknown_engineering_measurement', "engineering.$index");

                continue;
            }
            $amount = $this->operand($element, 'amount', $unit, $modelVersion, "engineering.$index.amount");
            if ($amount === null) {
                $this->diagnostic('insufficient_engineering_measurement', "engineering.$index");

                continue;
            }
            $key = 'engineering.'.$system.'.'.$measurement;
            if (isset($engineeringGroups[$key]) && $engineeringGroups[$key]['unit'] !== $unit) {
                $this->diagnostic('engineering_unit_conflict', "engineering.$index.unit");

                continue;
            }
            $engineeringGroups[$key]['unit'] = $unit;
            $engineeringRecord = $this->recordFromOperand($element, $amount);
            $engineeringRecord['_formula_operands'] = ['measurement' => (string) $amount->value];
            $engineeringGroups[$key]['items'][] = $this->item($amount->value, $engineeringRecord);
        }
        foreach ($engineeringGroups as $key => $group) {
            $items[$key] = $this->aggregate($key, $group['items'], $modelVersion, $group['unit']);
        }

        $items = array_filter(
            $items,
            static fn (QuantityData $quantity): bool => ! in_array('operand_provenance_missing', $quantity->reviewBlockers, true)
        );
        ksort($items);
        usort($this->diagnostics, static fn (array $a, array $b): int => [$a['path'], $a['code']] <=> [$b['path'], $b['code']]);

        return new QuantityCalculationResult($items, $this->diagnostics);
    }

    /** @param array<int, array{amount: BigDecimal, source: QuantitySource, evidence: array<int, string>, assumptions: array<int, string>}> $items */
    private function aggregate(string $key, array $items, string $modelVersion, ?string $unit = null): QuantityData
    {
        $validItems = [];
        foreach ($items as $item) {
            if ($item['source'] === QuantitySource::Estimated && $item['assumptions'] === []) {
                $this->diagnostic('operand_provenance_missing', $item['identity']);

                continue;
            }
            $validItems[] = $item;
        }
        $items = $validItems;
        if ($items === []) {
            return new QuantityData(
                key: $key, unit: $unit ?? (new QuantityFormulaCatalog)->definition($key)['unit'], amount: '0.000000',
                formulaKey: (new QuantityFormulaCatalog)->definition($key)['formula'], formulaVersion: QuantityFormulaCatalog::VERSION,
                formulaInputs: ['items' => []], source: QuantitySource::Estimated, evidenceIds: [], modelVersion: $modelVersion,
                reviewBlockers: ['operand_provenance_missing'],
            );
        }
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
        $formulaItems = array_map(static fn (array $item): QuantityFormulaItemData => new QuantityFormulaItemData(
            identity: $item['identity'], amount: (string) $item['amount'], namedOperands: $item['named_operands'],
            source: $item['source'], evidenceIds: $item['evidence'], assumptions: $item['assumptions'],
            contexts: $item['contexts'], provenanceVersions: $item['provenance_versions'],
        ), $items);

        return new QuantityData(
            key: $key, unit: $unit ?? $catalog['unit'], amount: (string) $sum->toScale(6, RoundingMode::HalfUp),
            formulaKey: $catalog['formula'], formulaVersion: QuantityFormulaCatalog::VERSION,
            formulaInputs: ['items' => array_map(static fn (QuantityFormulaItemData $item): array => $item->toArray(), $formulaItems)], source: $source,
            evidenceIds: $evidence, modelVersion: $modelVersion, assumptions: $assumptions,
            reviewBlockers: $source === QuantitySource::Estimated ? ['estimated_quantity_requires_review'] : [],
        );
    }

    /** @param array<string, mixed> $record @return array{amount: BigDecimal, source: QuantitySource, evidence: array<int, string>, assumptions: array<int, string>} */
    private function item(BigDecimal $amount, array $record): array
    {
        return [
            'amount' => $amount, 'source' => $this->source($record),
            'evidence' => $this->strings($record['evidence_ids'] ?? []),
            'assumptions' => $this->strings($record['assumptions'] ?? []),
            'identity' => (string) ($record['id'] ?? 'operand'),
            'contexts' => $this->strings($record['_operand_contexts'] ?? []),
            'provenance_versions' => $this->strings($record['_operand_provenance_versions'] ?? []),
            'named_operands' => is_array($record['_formula_operands'] ?? null) ? $record['_formula_operands'] : ['result' => (string) $amount],
        ];
    }

    /** @param array<string, mixed> $record */
    private function source(array $record): QuantitySource
    {
        return ($record['source'] ?? null) === 'estimated' || $this->strings($record['assumptions'] ?? []) !== [] || $this->strings($record['evidence_ids'] ?? []) === []
            ? QuantitySource::Estimated : QuantitySource::Evidenced;
    }

    /** @param array<string, mixed> $record */
    private function operand(array $record, string $field, string $unit, string $modelVersion, string $path): ?QuantityOperandData
    {
        try {
            return QuantityOperandData::fromRecord(
                $record,
                $field,
                $unit,
                $modelVersion,
                $this->scaleConfirmed,
                $field,
                (string) ($record['id'] ?? 'model:'.$modelVersion),
            );
        } catch (\InvalidArgumentException) {
            $this->diagnostic('invalid_typed_operand', $path);
            if ($this->strings($record['evidence_ids'] ?? []) === [] && ! is_array($record[$field] ?? null)) {
                $this->diagnostic('operand_provenance_missing', $path);
            }

            return null;
        }
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function recordFromOperand(array $record, QuantityOperandData $operand): array
    {
        return $this->recordFromOperands($record, [$operand]);
    }

    /** @param array<string, mixed> $record @param array<int, QuantityOperandData> $operands @return array<string, mixed> */
    private function recordFromOperands(array $record, array $operands): array
    {
        $evidence = [];
        $assumptions = [];
        $source = QuantitySource::Evidenced;
        $contexts = [];
        $versions = [];
        foreach ($operands as $operand) {
            $evidence = [...$evidence, ...$operand->evidenceIds];
            $assumptions = [...$assumptions, ...$operand->assumptions];
            $contexts[] = $operand->contextId;
            $versions[] = $operand->provenanceVersion;
            if ($operand->source === QuantitySource::Estimated) {
                $source = QuantitySource::Estimated;
            }
        }
        if (count(array_unique($contexts)) > 1) {
            $this->diagnostic('operand_context_conflict', (string) ($record['id'] ?? 'operand'));
            $evidence = [];
            $assumptions = [];
            $source = QuantitySource::Estimated;
        }

        $record['evidence_ids'] = $this->uniqueSorted($evidence);
        $record['assumptions'] = $this->uniqueSorted($assumptions);
        $record['source'] = $source->value;
        $record['_operand_contexts'] = $this->uniqueSorted($contexts);
        $record['_operand_provenance_versions'] = $this->uniqueSorted($versions);

        return $record;
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
        $identities = array_map(static fn (array $point): string => (string) $point[0].','.(string) $point[1], $points);
        if (count($identities) !== count(array_unique($identities))) {
            $this->diagnostic('duplicate_polygon_vertex', $path);

            return null;
        }
        $edgeCount = count($points);
        for ($i = 0; $i < $edgeCount; $i++) {
            for ($j = $i + 1; $j < $edgeCount; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $edgeCount - 1)) {
                    continue;
                }
                if ($this->segmentsProperlyIntersect($points[$i], $points[($i + 1) % $edgeCount], $points[$j], $points[($j + 1) % $edgeCount])) {
                    $this->diagnostic('self_intersecting_polygon', $path);

                    return null;
                }
            }
        }
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
        $area = $sum->abs()->dividedBy('2', $sum->getScale() + 1, RoundingMode::Unnecessary)->strippedOfTrailingZeros();
        if ($area->isZero()) {
            $this->diagnostic('degenerate_polygon', $path);

            return null;
        }

        return $area;
    }

    /** @param array<string, mixed> $model @return array<int, array<string, mixed>> */
    private function records(array $model, string $key): array
    {
        if (! array_key_exists($key, $model)) {
            return [];
        }
        if (! is_array($model[$key])) {
            $this->diagnostic('collection_must_be_array', $key);

            return [];
        }
        if (count($model[$key]) > 10_000) {
            $this->diagnostic('collection_resource_limit_exceeded', $key);

            return [];
        }

        $records = [];
        foreach (array_values($model[$key]) as $index => $record) {
            if (! is_array($record)) {
                $this->diagnostic('record_must_be_array', "$key.$index");

                continue;
            }
            $records[] = $record;
        }

        return $records;
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

    private function polygonIdentity(mixed $polygon): ?string
    {
        if (! is_array($polygon) || count($polygon) < 3) {
            return null;
        }

        $points = array_map(static fn (mixed $point): string => is_array($point) && count($point) === 2
            ? (string) $point[0].','.(string) $point[1] : '', array_values($polygon));
        if (in_array('', $points, true)) {
            return null;
        }

        $variants = [];
        foreach ([$points, array_reverse($points)] as $direction) {
            foreach (array_keys($direction) as $offset) {
                $variants[] = implode('|', [...array_slice($direction, $offset), ...array_slice($direction, 0, $offset)]);
            }
        }
        sort($variants, SORT_STRING);

        return $variants[0];
    }

    /** @param array<int, mixed> $first @param array<int, mixed> $second */
    private function polygonsProperlyIntersect(array $first, array $second): bool
    {
        for ($i = 0; $i < count($first); $i++) {
            for ($j = 0; $j < count($second); $j++) {
                if ($this->segmentsProperlyIntersect($first[$i], $first[($i + 1) % count($first)], $second[$j], $second[($j + 1) % count($second)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function segmentsProperlyIntersect(mixed $a, mixed $b, mixed $c, mixed $d): bool
    {
        if (! is_array($a) || ! is_array($b) || ! is_array($c) || ! is_array($d)) {
            return false;
        }
        $o1 = $this->orientation($a, $b, $c);
        $o2 = $this->orientation($a, $b, $d);
        $o3 = $this->orientation($c, $d, $a);
        $o4 = $this->orientation($c, $d, $b);

        return $o1 !== 0 && $o2 !== 0 && $o3 !== 0 && $o4 !== 0 && $o1 !== $o2 && $o3 !== $o4;
    }

    private function orientation(array $a, array $b, array $c): int
    {
        $ax = $this->decimal($a[0] ?? null);
        $ay = $this->decimal($a[1] ?? null);
        $bx = $this->decimal($b[0] ?? null);
        $by = $this->decimal($b[1] ?? null);
        $cx = $this->decimal($c[0] ?? null);
        $cy = $this->decimal($c[1] ?? null);
        if ($ax === null || $ay === null || $bx === null || $by === null || $cx === null || $cy === null) {
            return 0;
        }
        $cross = $bx->minus($ax)->multipliedBy($cy->minus($ay))->minus($by->minus($ay)->multipliedBy($cx->minus($ax)));

        return $cross->compareTo(BigDecimal::zero());
    }
}
