<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\VisionBuildingModelInputData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\FusedGeometryElementData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryFusionResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryFusionService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolver;
use InvalidArgumentException;

final readonly class GeometryBuildingModelInputMapper
{
    private const MAX_AUTOMATIC_VECTOR_ELEMENTS = 2_000;

    public function __construct(
        private GeometryFusionService $fusion = new GeometryFusionService,
        private ScaleResolver $scaleResolver = new ScaleResolver,
    ) {}

    /** @param array<string, int> $evidenceIdsByRef */
    public function map(
        ?VisionAnalysisData $vision,
        ?VectorGeometryData $vector,
        array $evidenceIdsByRef,
        string $floorKey = 'floor-1',
        ?GeometryConfirmationData $confirmation = null,
    ): VisionBuildingModelInputData {
        if ($vision === null && $vector === null) {
            throw new InvalidArgumentException('Geometry source is required.');
        }

        $elements = [];
        $issues = [];
        $vectorScales = [];
        $visionScales = [];

        if ($vector !== null) {
            [$vectorElements, $vectorIssues] = $confirmation === null
                ? $this->vectorElements($vector)
                : $this->confirmedVectorElements($vector, $confirmation);
            $elements = [...$elements, ...$vectorElements];
            $issues = [...$issues, ...$vectorIssues];
            $vectorScales = $confirmation === null
                ? $this->vectorScaleCandidates($vector, $vectorElements)
                : $this->confirmationScaleCandidates($vector, $confirmation, $vectorElements);
        }
        if ($vision !== null) {
            [$visionElements, $visionIssues] = $this->visionElements($vision);
            $elements = [...$elements, ...$visionElements];
            $issues = [...$issues, ...$visionIssues];
            $visionScales = $this->visionScaleCandidates($vision);
        }

        $elements = $this->namespaceElements($elements, $floorKey);

        $fused = $this->fusion->fuse($elements);
        $geometry = new GeometryFusionResult($fused->elements, $fused->sourceElements, [...$fused->issues, ...$issues]);

        return new VisionBuildingModelInputData(
            $this->scaleResolver->resolve($vectorScales, $visionScales, null),
            $geometry,
            [],
            [],
            $evidenceIdsByRef,
            'geometry-input-mapper:v1',
            $floorKey,
        );
    }

    /** @return array{list<FusedGeometryElementData>, list<array{code: string, severity: string, element_key: string, evidence_refs: list<string>}>} */
    private function confirmedVectorElements(VectorGeometryData $vector, GeometryConfirmationData $confirmation): array
    {
        if (! hash_equals($vector->sourceFingerprint, $confirmation->sourceFingerprint)
            || ! hash_equals($vector->payloadSha256(), $confirmation->geometryPayloadSha256)) {
            throw new InvalidArgumentException('geometry_confirmation_source_mismatch');
        }
        $entities = array_column($vector->entities, null, 'handle');
        $values = array_column([...$vector->texts, ...$vector->dimensions], null, 'handle');
        $scale = $this->confirmedScale($vector, $confirmation, $entities, $values);
        $wallGeometry = [];
        foreach ($confirmation->elements as $mapping) {
            if ($mapping['type'] === 'wall') {
                $wallGeometry[$mapping['key']] = $this->confirmedWallGeometry($mapping['segment_handles'], $entities);
            }
        }
        $elements = [];
        foreach ($confirmation->elements as $mapping) {
            $handle = match ($mapping['type']) {
                'room' => $mapping['boundary_handle'],
                'wall' => $mapping['segment_handles'][0],
                'opening' => $mapping['dimension_handle'],
            };
            $entity = $entities[$handle] ?? $values[$handle] ?? throw new InvalidArgumentException('geometry_confirmation_entity_unknown');
            $geometry = match ($mapping['type']) {
                'room' => $this->confirmedRoomGeometry($entity),
                'wall' => $wallGeometry[$mapping['key']],
                'opening' => $this->confirmedOpeningGeometry($mapping, $entities, $values, $wallGeometry, $scale),
            };
            $reference = $mapping['type'] === 'opening' ? 'confirmation:'.$handle : 'vector:'.$handle;
            $elements[] = new FusedGeometryElementData(
                $mapping['key'], $mapping['type'], $geometry, 'vector', $reference,
                $vector->sourceFingerprint, $this->vectorPageNumber($entity), 'source_units_v1',
                $vector->runtimeVersion, 'geometry-confirmation:v1', 1.0, [], 'source-units:v1',
            );
        }

        return [$elements, []];
    }

    private function confirmedRoomGeometry(array $entity): array
    {
        $points = $entity['points'] ?? null;
        if (! is_array($points) || count($points) < 3) {
            throw new InvalidArgumentException('geometry_confirmation_room_invalid');
        }

        return ['polygon' => $points];
    }

    /** @param list<string> $handles @param array<string, array<string, mixed>> $entities */
    private function confirmedWallGeometry(array $handles, array $entities): array
    {
        $segments = [];
        foreach ($handles as $handle) {
            $points = $entities[$handle]['points'] ?? null;
            if (! is_array($points) || count($points) !== 2) {
                throw new InvalidArgumentException('geometry_confirmation_wall_invalid');
            }
            $segments[] = $points;
        }
        $origin = $segments[0][0];
        $direction = [(float) $segments[0][1][0] - (float) $origin[0], (float) $segments[0][1][1] - (float) $origin[1]];
        $length = hypot($direction[0], $direction[1]);
        if ($length <= 0) {
            throw new InvalidArgumentException('geometry_confirmation_wall_invalid');
        }
        $unit = [$direction[0] / $length, $direction[1] / $length];
        $projected = [];
        foreach ($segments as $segment) {
            foreach ($segment as $point) {
                $cross = abs(((float) $point[0] - (float) $origin[0]) * $unit[1] - ((float) $point[1] - (float) $origin[1]) * $unit[0]);
                if ($cross > max(1.0E-7, $length * 1.0E-7)) {
                    throw new InvalidArgumentException('geometry_confirmation_wall_not_collinear');
                }
                $projected[] = ['value' => ((float) $point[0] - (float) $origin[0]) * $unit[0]
                    + ((float) $point[1] - (float) $origin[1]) * $unit[1], 'point' => $point];
            }
        }
        usort($projected, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        return ['start' => $projected[0]['point'], 'end' => $projected[array_key_last($projected)]['point'],
            'thickness' => null, 'height' => null];
    }

    private function confirmedOpeningGeometry(array $mapping, array $entities, array $values, array $walls, float $scale): array
    {
        $left = $entities[$mapping['boundary_handles'][0]]['points'] ?? null;
        $right = $entities[$mapping['boundary_handles'][1]]['points'] ?? null;
        $wall = $walls[$mapping['wall_key']] ?? null;
        $text = $values[$mapping['dimension_handle']]['text'] ?? null;
        if (! is_array($left) || count($left) !== 2 || ! is_array($right) || count($right) !== 2 || ! is_array($wall)
            || ! is_string($text) || preg_match('/(?:OPENING\s*)?(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(mm|cm|m|in|ft)\b/i', $text, $match) !== 1) {
            throw new InvalidArgumentException('geometry_confirmation_opening_evidence_invalid');
        }
        $axis = [(float) $wall['end'][0] - (float) $wall['start'][0], (float) $wall['end'][1] - (float) $wall['start'][1]];
        $wallLength = hypot($axis[0], $axis[1]);
        $axis = [$axis[0] / $wallLength, $axis[1] / $wallLength];
        $positions = static fn (array $segment): array => array_map(static fn (array $point): float => ((float) $point[0] - (float) $wall['start'][0]) * $axis[0] + ((float) $point[1] - (float) $wall['start'][1]) * $axis[1], $segment);
        $a = $positions($left);
        $b = $positions($right);
        sort($a);
        sort($b);
        $gapStart = min(max($a), max($b));
        $gapEnd = max(min($a), min($b));
        if ($gapEnd <= $gapStart) {
            throw new InvalidArgumentException('geometry_confirmation_opening_gap_invalid');
        }
        $factor = $this->unitMeters(strtolower($match[3]));
        $declaredWidth = (float) $match[1] * $factor / $scale;
        $gapWidth = $gapEnd - $gapStart;
        if (abs($declaredWidth - $gapWidth) > max(1.0E-6, $gapWidth * 1.0E-6)) {
            throw new InvalidArgumentException('geometry_confirmation_opening_dimension_mismatch');
        }

        return ['wall_key' => $mapping['wall_key'], 'opening_type' => $mapping['opening_type'],
            'offset' => $gapStart, 'width' => $gapWidth, 'height' => (float) $match[2] * $factor / $scale];
    }

    /** @param list<FusedGeometryElementData> $elements @return list<ScaleCandidateData> */
    private function confirmationScaleCandidates(VectorGeometryData $vector, GeometryConfirmationData $confirmation, array $elements): array
    {
        if ($elements === []) {
            return [];
        }

        $entities = array_column($vector->entities, null, 'handle');
        $values = array_column([...$vector->texts, ...$vector->dimensions], null, 'handle');
        $scale = $this->confirmedScale($vector, $confirmation, $entities, $values);

        return array_map(static fn (array $evidence): ScaleCandidateData => new ScaleCandidateData(
            'vector', $scale, ($evidence['role'] === 'measured_segment' ? 'vector:' : 'confirmation:')
                .($evidence['value_handle'] ?? $evidence['entity_handle']), $vector->sourceFingerprint,
            $elements[0]->pageNumber, 'source-units:v1', $vector->runtimeVersion, 'geometry-confirmation:v1', 1.0,
        ), $confirmation->scaleEvidence);
    }

    private function confirmedScale(VectorGeometryData $vector, GeometryConfirmationData $confirmation, array $entities, array $values): float
    {
        $candidates = [];
        foreach ($confirmation->scaleEvidence as $evidence) {
            if ($evidence['role'] === 'measured_segment') {
                $entity = $entities[$evidence['entity_handle']] ?? throw new InvalidArgumentException('geometry_confirmation_scale_entity_unknown');
                $points = $entity['points'] ?? null;
                [$first, $second] = $evidence['point_indexes'];
                if (! is_array($points) || ! isset($points[$first], $points[$second])) {
                    throw new InvalidArgumentException('geometry_confirmation_scale_segment_invalid');
                }
                $span = hypot((float) $points[$second][0] - (float) $points[$first][0], (float) $points[$second][1] - (float) $points[$first][1]);
                $candidates[] = (float) $evidence['real_world_value'] * $this->unitMeters($evidence['unit']) / $span;
            } elseif ($evidence['role'] === 'dimension') {
                $value = $values[$evidence['value_handle']]['text'] ?? null;
                $entity = $entities[$evidence['entity_handle']] ?? null;
                $points = is_array($entity) ? ($entity['points'] ?? null) : null;
                [$first, $second] = $evidence['point_indexes'];
                if (! is_string($value) || preg_match('/\b(\d+(?:\.\d+)?)\s*(mm|cm|m|in|ft)\b/i', $value, $match) !== 1
                    || ! is_array($points) || ! isset($points[$first], $points[$second])) {
                    throw new InvalidArgumentException('geometry_confirmation_dimension_invalid');
                }
                $span = hypot((float) $points[$second][0] - (float) $points[$first][0], (float) $points[$second][1] - (float) $points[$first][1]);
                $candidates[] = (float) $match[1] * $this->unitMeters(strtolower($match[2])) / $span;
            } else {
                $text = $values[$evidence['value_handle']]['text'] ?? null;
                if (! is_string($text) || preg_match('/(?:UNITS?|INSUNITS)\s*[:=]?\s*(mm|cm|m|in|ft)\b/i', $text, $match) !== 1) {
                    throw new InvalidArgumentException('geometry_confirmation_unit_declaration_invalid');
                }
                $candidates[] = $this->unitMeters(strtolower($match[1]));
            }
        }
        $first = $candidates[0] ?? throw new InvalidArgumentException('geometry_confirmation_scale_missing');
        foreach ($candidates as $candidate) {
            if (! is_finite($candidate) || $candidate <= 0 || abs($candidate - $first) > max(1.0E-12, $first * 1.0E-6)) {
                throw new InvalidArgumentException('geometry_confirmation_scale_conflict');
            }
        }
        $declared = match ($vector->sourceUnit) {
            'mm' => 0.001, 'cm' => 0.01, 'm' => 1.0, 'in' => 0.0254, 'ft' => 0.3048, default => null,
        };
        if ($vector->unitStatus === 'confirmed' && ($declared === null || abs($declared - $first) > 1.0E-12)) {
            throw new InvalidArgumentException('geometry_confirmation_scale_conflict');
        }

        return $first;
    }

    private function unitMeters(string $unit): float
    {
        return match ($unit) {
            'mm' => 0.001, 'cm' => 0.01, 'm' => 1.0, 'in' => 0.0254, 'ft' => 0.3048,
            default => throw new InvalidArgumentException('geometry_confirmation_unit_invalid'),
        };
    }

    /** @return array{list<FusedGeometryElementData>, list<array{code: string, severity: string, element_key: string, evidence_refs: list<string>}>} */
    private function visionElements(VisionAnalysisData $vision): array
    {
        $evidence = [];
        foreach ($vision->evidence as $item) {
            $evidence[$item->key] = $item;
        }
        $elements = [];
        $issues = [];
        foreach ($vision->elements as $item) {
            $locator = $evidence[$item->evidenceRef]->locator;
            $geometry = match ($item->type) {
                'room' => ['polygon' => $item->polygon],
                'wall' => ['start' => $item->polygon[0], 'end' => $item->polygon[1], 'thickness' => null, 'height' => null],
                'opening' => $item->geometry,
                'engineering_element' => $this->visionEngineeringGeometry($item->label, $item->polygon),
                default => null,
            };
            if ($geometry === null) {
                $issues[] = ['code' => 'geometry_element_unsupported', 'severity' => 'blocking', 'element_key' => $item->key, 'evidence_refs' => [$item->evidenceRef]];

                continue;
            }
            try {
                $elements[] = new FusedGeometryElementData(
                    $item->key, $item->type, $geometry, 'vision', $item->evidenceRef,
                    $locator['source_version'], $locator['page_number'], $locator['coordinate_space'],
                    'vision-contract:v1', $vision->modelVersion, $item->confidence, [], $locator['coordinate_space'],
                    $this->modelRoomName($item->type, $item->label),
                );
            } catch (InvalidArgumentException) {
                $issues[] = ['code' => 'geometry_element_unsupported', 'severity' => 'blocking', 'element_key' => $item->key, 'evidence_refs' => [$item->evidenceRef]];
            }
        }

        return [$elements, $issues];
    }

    private function visionEngineeringGeometry(?string $label, array $polygon): ?array
    {
        if ($label === null || ! in_array($label, ['outlet', 'switch', 'light', 'water_point', 'sewer_point', 'heating_point', 'ventilation_point', 'route', 'sewer_route'], true)) {
            return null;
        }

        return ['engineering_type' => $label, 'location' => $polygon[0],
            'path' => count($polygon) === 2 ? $polygon : null, 'room_key' => null];
    }

    /** @return array{list<FusedGeometryElementData>, list<array{code: string, severity: string, element_key: string, evidence_refs: list<string>}>} */
    private function vectorElements(VectorGeometryData $vector): array
    {
        $elements = [];
        $issues = [];
        foreach ($vector->entities as $entity) {
            $reference = 'vector:'.$entity['handle'];
            if (count($elements) >= self::MAX_AUTOMATIC_VECTOR_ELEMENTS) {
                $issues[] = [
                    'code' => 'geometry_element_unsupported',
                    'severity' => 'blocking',
                    'element_key' => 'vector-'.strtolower($entity['handle']),
                    'evidence_refs' => [$reference],
                ];

                break;
            }
            $type = $entity['type'];
            $geometryType = null;
            $geometry = null;
            if (($entity['semantic']['kind'] ?? null) === 'opening') {
                $semantic = $entity['semantic'];
                $geometryType = 'opening';
                $geometry = ['wall_key' => 'vector-'.strtolower((string) $semantic['wall_handle']),
                    'opening_type' => $semantic['opening_type'], 'offset' => $semantic['offset'],
                    'width' => $semantic['width'], 'height' => $semantic['height']];
            } elseif (in_array($type, ['lwpolyline', 'polyline'], true) && $entity['closed'] === true && count($entity['points']) >= 3) {
                $geometryType = 'room';
                $geometry = ['polygon' => $entity['points']];
            } elseif ($type === 'line') {
                $geometryType = 'wall';
                $geometry = ['start' => $entity['points'][0], 'end' => $entity['points'][1], 'thickness' => null, 'height' => null];
            }
            if ($geometry === null) {
                $issues[] = ['code' => 'geometry_element_unsupported', 'severity' => 'blocking', 'element_key' => 'vector-'.$entity['handle'], 'evidence_refs' => [$reference]];

                continue;
            }
            $page = $this->vectorPageNumber($entity);
            $transform = 'source-units:v1';
            $elementKey = 'vector-'.strtolower($entity['handle']);
            try {
                $elements[] = new FusedGeometryElementData(
                    $elementKey, $geometryType, $geometry, 'vector', $reference,
                    $vector->sourceFingerprint, $page, 'source_units_v1', $vector->runtimeVersion,
                    $vector->runtimeVersion, 1.0, [], $transform,
                );
            } catch (InvalidArgumentException) {
                $issues[] = ['code' => 'geometry_element_unsupported', 'severity' => 'blocking', 'element_key' => $elementKey, 'evidence_refs' => [$reference]];
            }
        }

        return [$elements, $issues];
    }

    private function vectorPageNumber(array $entity): int
    {
        $layout = $entity['layout'] ?? null;
        if (is_string($layout) && preg_match('/(?:page|layout)[-_ ]?(\d+)/i', $layout, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        return 1;
    }

    /** @param list<FusedGeometryElementData> $elements @return list<FusedGeometryElementData> */
    private function namespaceElements(array $elements, string $floorKey): array
    {
        $keys = [];
        foreach ($elements as $element) {
            $keys[$element->key] = $this->namespacedElementKey($floorKey, $element->key);
        }

        return array_map(static function (FusedGeometryElementData $element) use ($keys): FusedGeometryElementData {
            $geometry = $element->geometry;
            if ($element->type === 'opening' && isset($keys[$geometry['wall_key']])) {
                $geometry['wall_key'] = $keys[$geometry['wall_key']];
            }
            if ($element->type === 'engineering_element' && is_string($geometry['room_key'] ?? null) && isset($keys[$geometry['room_key']])) {
                $geometry['room_key'] = $keys[$geometry['room_key']];
            }

            return new FusedGeometryElementData(
                $keys[$element->key],
                $element->type,
                $geometry,
                $element->sourceType,
                $element->evidenceRef,
                $element->sourceFingerprint,
                $element->pageNumber,
                $element->coordinateSpace,
                $element->runtimeVersion,
                $element->modelVersion,
                $element->confidence,
                $element->provenance,
                $element->coordinateTransform,
                $element->label,
            );
        }, $elements);
    }

    private function namespacedElementKey(string $floorKey, string $elementKey): string
    {
        $key = $floorKey.'-'.$elementKey;

        return strlen($key) <= 128
            ? $key
            : substr($key, 0, 115).'-'.substr(hash('sha256', $key), 0, 12);
    }

    private function modelRoomName(string $type, ?string $label): ?string
    {
        if ($type !== 'room' || $label === null) {
            return null;
        }

        try {
            return BuildingModelSchema::nullableLabel($label, 'Room name');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param list<FusedGeometryElementData> $elements @return list<ScaleCandidateData> */
    private function vectorScaleCandidates(VectorGeometryData $vector, array $elements): array
    {
        if ($elements === []) {
            return [];
        }
        $unitScale = match ($vector->sourceUnit) {
            'mm' => 0.001, 'cm' => 0.01, 'm' => 1.0, 'in' => 0.0254, 'ft' => 0.3048, default => null,
        };
        $reference = $elements[0]->evidenceRef;
        $candidates = [];
        if ($vector->unitStatus === 'confirmed' && $unitScale !== null) {
            $candidates[] = new ScaleCandidateData('vector', $unitScale, $reference, $vector->sourceFingerprint, $elements[0]->pageNumber, 'source-units:v1', $vector->runtimeVersion, $vector->runtimeVersion, 1.0);
        }
        foreach ($vector->scaleCandidates as $candidate) {
            $candidates[] = new ScaleCandidateData('vector', (float) $candidate['value'], $reference, $vector->sourceFingerprint, $elements[0]->pageNumber, 'source-units:v1', $vector->runtimeVersion, $vector->runtimeVersion, (float) ($candidate['confidence'] ?? 1.0));
        }

        return $candidates;
    }

    /** @return list<ScaleCandidateData> */
    private function visionScaleCandidates(VisionAnalysisData $vision): array
    {
        $evidence = [];
        foreach ($vision->evidence as $item) {
            $evidence[$item->key] = $item->locator;
        }

        return array_map(static function ($candidate) use ($vision, $evidence): ScaleCandidateData {
            $locator = $evidence[$candidate->evidenceRef];

            return new ScaleCandidateData(
                'vision', $candidate->metersPerUnit, $candidate->evidenceRef, $locator['source_version'],
                $locator['page_number'], $locator['coordinate_space'], 'vision-contract:v1', $vision->modelVersion,
                $candidate->confidence, $locator['coordinate_space'],
            );
        }, $vision->scaleCandidates);
    }
}
