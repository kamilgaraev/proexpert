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

    private int $broadPhasePairInspections = 0;

    private int $topologyCandidateComparisons = 0;

    /** @param array<string, mixed> $model */
    public function calculate(array $model): QuantityCalculationResult
    {
        $this->diagnostics = [];
        $this->broadPhasePairInspections = 0;
        $this->topologyCandidateComparisons = 0;
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
                    $roomPolygons[] = ['polygon' => $room['polygon'], 'path' => "rooms.$index.polygon", 'bounds' => $this->polygonExactBounds($room['polygon'])];
                }
            }
            $roomRecord = $areaOperand === null
                ? $room + ['_operand_contexts' => ['model:'.$modelVersion], '_operand_provenance_versions' => [$modelVersion]]
                : $this->recordFromOperand($room, $areaOperand);
            $roomRecord['_formula_operands'] = $areaOperand !== null ? ['area' => $areaOperand->toFormulaOperand()] : [
                'area' => $this->derivedFormulaOperand('area', $area, 'm2', $roomRecord),
            ];
            $roomItems[] = $this->item($area, $roomRecord);
        }
        $buckets = [];
        $extents = array_map(static function (array $entry): BigDecimal {
            $width = $entry['bounds'][2]->minus($entry['bounds'][0]);
            $height = $entry['bounds'][3]->minus($entry['bounds'][1]);

            return $width->isGreaterThan($height) ? $width : $height;
        }, $roomPolygons);
        usort($extents, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));
        $cellSize = $extents === [] ? BigDecimal::one() : $extents[intdiv(count($extents), 2)]->multipliedBy('2');
        foreach ($roomPolygons as $index => $entry) {
            $cells = $this->spatialCells($entry['bounds'], $cellSize);
            if (count($cells) > 4096) {
                $this->diagnostic('polygon_spatial_budget_exceeded', $entry['path']);
                $ambiguousOverlap = true;

                continue;
            }
            foreach ($cells as $cell) {
                $buckets[$cell][] = $index;
            }
        }
        ksort($buckets, SORT_STRING);
        $inspectedPairs = [];
        foreach ($buckets as $indices) {
            sort($indices, SORT_NUMERIC);
            for ($i = 0; $i < count($indices); $i++) {
                for ($j = $i + 1; $j < count($indices); $j++) {
                    $this->broadPhasePairInspections++;
                    $pairKey = $indices[$i].':'.$indices[$j];
                    if (isset($inspectedPairs[$pairKey])) {
                        continue;
                    }
                    $inspectedPairs[$pairKey] = true;
                    $first = $roomPolygons[$indices[$i]];
                    $second = $roomPolygons[$indices[$j]];
                    if (! $this->boundsHavePositiveAreaOverlap($first['bounds'], $second['bounds'])) {
                        continue;
                    }
                    $this->topologyCandidateComparisons++;
                    if ($this->polygonsProperlyIntersect($first['polygon'], $second['polygon'])) {
                        $this->diagnostic('ambiguous_polygon_overlap', $second['path']);
                        $ambiguousOverlap = true;
                    }
                }
            }
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
            $openingArea = $width->value->multipliedBy($height->value);
            $openingRecord['_derived_area_operand'] = $this->derivedFormulaOperand('opening_area', $openingArea, 'm2', $openingRecord);
            $openings[$id] = $openingRecord + ['_area' => $openingArea];
            $openingItems[] = $this->item($openingArea, $openingRecord);
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
            $wallRecord['_formula_operands']['side_multiplier'] = $this->derivedFormulaOperand('side_multiplier', $sideMultiplier, 'count', $wallRecord);
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
            $duplicateRefs = count($refs) !== count(array_unique($refs));
            if ($duplicateRefs) {
                $this->diagnostic('duplicate_opening_reference', "walls.$index.opening_ids");
            }
            $refs = $this->uniqueSorted($refs);
            $valid = ! $duplicateRefs;
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
                $openingOperands[$ref] = $opening['_derived_area_operand'];
                $netEvidence = [...$netEvidence, ...$this->strings($opening['evidence_ids'] ?? [])];
                $netAssumptions = [...$netAssumptions, ...$this->strings($opening['assumptions'] ?? [])];
                if ($this->source($opening) === QuantitySource::Estimated) {
                    $netSource = QuantitySource::Estimated;
                }
                $netContexts = [...$netContexts, ...$openingContexts];
                $netVersions = [...$netVersions, ...$this->strings($opening['_operand_provenance_versions'] ?? [])];
            }
            if ($net->isNegative()) {
                $this->diagnostic('opening_area_exceeds_wall_area', "walls.$index.opening_ids");

                continue;
            }
            if (! $valid) {
                continue;
            }
            ksort($openingOperands, SORT_STRING);
            $netItems[] = [
                'amount' => $net, 'source' => $netSource, 'evidence' => $netEvidence,
                'assumptions' => $netAssumptions, 'identity' => (string) ($wall['id'] ?? "wall-$index"),
                'contexts' => $this->uniqueSorted($netContexts),
                'provenance_versions' => $this->uniqueSorted($netVersions),
                'named_operands' => [
                    'gross_wall_area' => $this->derivedFormulaOperand('gross_wall_area', $gross, 'm2', $wallRecord),
                    'openings' => $openingOperands,
                ],
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

        return new QuantityCalculationResult($items, $this->diagnostics, [
            'broad_phase_pair_inspections' => $this->broadPhasePairInspections,
            'topology_candidate_comparisons' => $this->topologyCandidateComparisons,
        ]);
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
        usort($items, fn (array $a, array $b): int => $this->canonicalItem($key, $a) <=> $this->canonicalItem($key, $b));
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
        $formulaItems = array_map(fn (array $item): QuantityFormulaItemData => new QuantityFormulaItemData(
            identity: hash('sha256', $this->canonicalItem($key, $item)), amount: (string) $item['amount'], namedOperands: $item['named_operands'],
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
        $record['_formula_operands'] = [];
        foreach ($operands as $operand) {
            $record['_formula_operands'][$operand->role] = $operand->toFormulaOperand();
        }

        return $record;
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function derivedFormulaOperand(string $role, BigDecimal $value, string $unit, array $record): array
    {
        return [
            'role' => $role, 'value' => (string) $value, 'unit' => $unit,
            'source' => $this->source($record)->value,
            'evidence_ids' => $this->strings($record['evidence_ids'] ?? []),
            'assumptions' => $this->strings($record['assumptions'] ?? []),
            'context_id' => $this->strings($record['_operand_contexts'] ?? [])[0] ?? 'derived',
            'provenance_version' => $this->strings($record['_operand_provenance_versions'] ?? [])[0] ?? QuantityFormulaCatalog::VERSION,
        ];
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
        foreach ($points as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                || $this->coordinate($point[0]) === null || $this->coordinate($point[1]) === null) {
                $this->diagnostic('invalid_polygon_coordinate', $path);

                return null;
            }
        }
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
                if ($this->segmentsIntersectOrTouch($points[$i], $points[($i + 1) % $edgeCount], $points[$j], $points[($j + 1) % $edgeCount])) {
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
            $x = $this->coordinate($point[0]);
            $y = $this->coordinate($point[1]);
            $nextX = $this->coordinate($next[0]);
            $nextY = $this->coordinate($next[1]);
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

    /** @param array<string, mixed> $item */
    private function canonicalItem(string $formulaKey, array $item): string
    {
        return json_encode([
            'formula' => $formulaKey, 'identity' => $item['identity'],
            'named_operands' => $item['named_operands'], 'contexts' => $item['contexts'],
            'provenance_versions' => $item['provenance_versions'], 'source' => $item['source']->value,
            'evidence' => $item['evidence'], 'assumptions' => $item['assumptions'],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
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

        foreach ($first as $point) {
            if ($this->pointInPolygon($point, $second) === 1) {
                return true;
            }
        }
        foreach ($second as $point) {
            if ($this->pointInPolygon($point, $first) === 1) {
                return true;
            }
        }

        if ($this->boundingBoxesHavePositiveAreaOverlap($first, $second)) {
            $collinearOverlaps = 0;
            for ($i = 0; $i < count($first); $i++) {
                for ($j = 0; $j < count($second); $j++) {
                    if ($this->segmentsCollinearlyOverlap($first[$i], $first[($i + 1) % count($first)], $second[$j], $second[($j + 1) % count($second)])) {
                        $collinearOverlaps++;
                    }
                }
            }
            if ($collinearOverlaps >= 2) {
                return true;
            }
        }

        return false;
    }

    private function segmentsIntersectOrTouch(array $a, array $b, array $c, array $d): bool
    {
        if ($this->segmentsProperlyIntersect($a, $b, $c, $d)) {
            return true;
        }

        return ($this->orientation($a, $b, $c) === 0 && $this->pointOnSegment($c, $a, $b))
            || ($this->orientation($a, $b, $d) === 0 && $this->pointOnSegment($d, $a, $b))
            || ($this->orientation($c, $d, $a) === 0 && $this->pointOnSegment($a, $c, $d))
            || ($this->orientation($c, $d, $b) === 0 && $this->pointOnSegment($b, $c, $d));
    }

    private function pointOnSegment(array $point, array $a, array $b): bool
    {
        $px = $this->coordinate($point[0]);
        $py = $this->coordinate($point[1]);
        $ax = $this->coordinate($a[0]);
        $ay = $this->coordinate($a[1]);
        $bx = $this->coordinate($b[0]);
        $by = $this->coordinate($b[1]);
        if ($px === null || $py === null || $ax === null || $ay === null || $bx === null || $by === null) {
            return false;
        }

        $minX = $ax->isLessThan($bx) ? $ax : $bx;
        $maxX = $ax->isGreaterThan($bx) ? $ax : $bx;
        $minY = $ay->isLessThan($by) ? $ay : $by;
        $maxY = $ay->isGreaterThan($by) ? $ay : $by;

        return $px->isGreaterThanOrEqualTo($minX) && $px->isLessThanOrEqualTo($maxX)
            && $py->isGreaterThanOrEqualTo($minY) && $py->isLessThanOrEqualTo($maxY);
    }

    /** 1 inside, 0 boundary, -1 outside */
    private function pointInPolygon(array $point, array $polygon): int
    {
        $winding = 0;
        $py = $this->coordinate($point[1]);
        if ($py === null) {
            return -1;
        }
        for ($i = 0; $i < count($polygon); $i++) {
            $a = $polygon[$i];
            $b = $polygon[($i + 1) % count($polygon)];
            if ($this->orientation($a, $b, $point) === 0 && $this->pointOnSegment($point, $a, $b)) {
                return 0;
            }
            $ay = $this->coordinate($a[1]);
            $by = $this->coordinate($b[1]);
            if ($ay === null || $by === null) {
                return -1;
            }
            if ($ay->isLessThanOrEqualTo($py) && $by->isGreaterThan($py) && $this->orientation($a, $b, $point) > 0) {
                $winding++;
            } elseif ($ay->isGreaterThan($py) && $by->isLessThanOrEqualTo($py) && $this->orientation($a, $b, $point) < 0) {
                $winding--;
            }
        }

        return $winding === 0 ? -1 : 1;
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
        $ax = $this->coordinate($a[0] ?? null);
        $ay = $this->coordinate($a[1] ?? null);
        $bx = $this->coordinate($b[0] ?? null);
        $by = $this->coordinate($b[1] ?? null);
        $cx = $this->coordinate($c[0] ?? null);
        $cy = $this->coordinate($c[1] ?? null);
        if ($ax === null || $ay === null || $bx === null || $by === null || $cx === null || $cy === null) {
            return 0;
        }
        $cross = $bx->minus($ax)->multipliedBy($cy->minus($ay))->minus($by->minus($ay)->multipliedBy($cx->minus($ax)));

        return $cross->compareTo(BigDecimal::zero());
    }

    private function segmentsCollinearlyOverlap(array $a, array $b, array $c, array $d): bool
    {
        if ($this->orientation($a, $b, $c) !== 0 || $this->orientation($a, $b, $d) !== 0) {
            return false;
        }
        $ax = $this->coordinate($a[0]);
        $ay = $this->coordinate($a[1]);
        $bx = $this->coordinate($b[0]);
        $by = $this->coordinate($b[1]);
        $cx = $this->coordinate($c[0]);
        $cy = $this->coordinate($c[1]);
        $dx = $this->coordinate($d[0]);
        $dy = $this->coordinate($d[1]);
        if ($ax === null || $ay === null || $bx === null || $by === null || $cx === null || $cy === null || $dx === null || $dy === null) {
            return false;
        }
        if (! $ax->isEqualTo($bx)) {
            if ($ax->isGreaterThan($bx)) {
                [$ax, $bx] = [$bx, $ax];
            }
            if ($cx->isGreaterThan($dx)) {
                [$cx, $dx] = [$dx, $cx];
            }
            $left = $ax->isGreaterThan($cx) ? $ax : $cx;
            $right = $bx->isLessThan($dx) ? $bx : $dx;

            return $left->isLessThan($right);
        }
        if ($ay->isGreaterThan($by)) {
            [$ay, $by] = [$by, $ay];
        }
        if ($cy->isGreaterThan($dy)) {
            [$cy, $dy] = [$dy, $cy];
        }
        $bottom = $ay->isGreaterThan($cy) ? $ay : $cy;
        $top = $by->isLessThan($dy) ? $by : $dy;

        return $bottom->isLessThan($top);
    }

    /** @return array{BigDecimal, BigDecimal, BigDecimal, BigDecimal} */
    private function polygonExactBounds(array $polygon): array
    {
        $xs = array_map(fn (array $point): BigDecimal => $this->coordinate($point[0]) ?? BigDecimal::zero(), $polygon);
        $ys = array_map(fn (array $point): BigDecimal => $this->coordinate($point[1]) ?? BigDecimal::zero(), $polygon);
        usort($xs, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));
        usort($ys, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));

        return [$xs[0], $ys[0], $xs[array_key_last($xs)], $ys[array_key_last($ys)]];
    }

    /** @param array{BigDecimal, BigDecimal, BigDecimal, BigDecimal} $bounds @return array<int, string> */
    private function spatialCells(array $bounds, BigDecimal $cellSize): array
    {
        $minX = (int) (string) $bounds[0]->dividedBy($cellSize, 0, RoundingMode::Floor);
        $minY = (int) (string) $bounds[1]->dividedBy($cellSize, 0, RoundingMode::Floor);
        $maxX = (int) (string) $bounds[2]->dividedBy($cellSize, 0, RoundingMode::Floor);
        $maxY = (int) (string) $bounds[3]->dividedBy($cellSize, 0, RoundingMode::Floor);
        if (($maxX - $minX + 1) * ($maxY - $minY + 1) > 4096) {
            return array_fill(0, 4097, 'overflow');
        }
        $cells = [];
        for ($x = $minX; $x <= $maxX; $x++) {
            for ($y = $minY; $y <= $maxY; $y++) {
                $cells[] = $x.':'.$y;
            }
        }

        return $cells;
    }

    /** @param array{BigDecimal, BigDecimal, BigDecimal, BigDecimal} $first @param array{BigDecimal, BigDecimal, BigDecimal, BigDecimal} $second */
    private function boundsHavePositiveAreaOverlap(array $first, array $second): bool
    {
        return $first[0]->isLessThan($second[2]) && $first[2]->isGreaterThan($second[0])
            && $first[1]->isLessThan($second[3]) && $first[3]->isGreaterThan($second[1]);
    }

    private function boundingBoxesHavePositiveAreaOverlap(array $first, array $second): bool
    {
        $bounds = static function (array $polygon): array {
            $xs = array_map(static fn (array $p): BigDecimal => BigDecimal::of($p[0]), $polygon);
            $ys = array_map(static fn (array $p): BigDecimal => BigDecimal::of($p[1]), $polygon);
            usort($xs, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));
            usort($ys, static fn (BigDecimal $a, BigDecimal $b): int => $a->compareTo($b));

            return [$xs[0], $ys[0], $xs[array_key_last($xs)], $ys[array_key_last($ys)]];
        };
        $a = $bounds($first);
        $b = $bounds($second);

        return $a[0]->isLessThan($b[2]) && $a[2]->isGreaterThan($b[0])
            && $a[1]->isLessThan($b[3]) && $a[3]->isGreaterThan($b[1]);
    }

    private function coordinate(mixed $value): ?BigDecimal
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        try {
            return BigDecimal::of($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
