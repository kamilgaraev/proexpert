<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

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
    ): VisionBuildingModelInputData {
        if ($vision === null && $vector === null) {
            throw new InvalidArgumentException('Geometry source is required.');
        }

        $elements = [];
        $issues = [];
        $vectorScales = [];
        $visionScales = [];

        if ($vector !== null) {
            [$vectorElements, $vectorIssues] = $this->vectorElements($vector);
            $elements = [...$elements, ...$vectorElements];
            $issues = [...$issues, ...$vectorIssues];
            $vectorScales = $this->vectorScaleCandidates($vector, $vectorElements);
        }
        if ($vision !== null) {
            [$visionElements, $visionIssues] = $this->visionElements($vision);
            $elements = [...$elements, ...$visionElements];
            $issues = [...$issues, ...$visionIssues];
            $visionScales = $this->visionScaleCandidates($vision);
        }

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
            $elements[] = new FusedGeometryElementData(
                $item->key, $item->type, $geometry, 'vision', $item->evidenceRef,
                $locator['source_version'], $locator['page_number'], $locator['coordinate_space'],
                'vision-contract:v1', $vision->modelVersion, $item->confidence, [], $locator['coordinate_space'],
            );
        }

        return [$elements, $issues];
    }

    private function visionEngineeringGeometry(?string $label, array $polygon): ?array
    {
        if ($label === null || ! in_array($label, ['outlet', 'switch', 'light', 'water_point', 'sewer_point', 'heating_point', 'ventilation_point', 'route'], true)) {
            return null;
        }

        return ['engineering_type' => $label, 'location' => $polygon[0], 'room_key' => null];
    }

    /** @return array{list<FusedGeometryElementData>, list<array{code: string, severity: string, element_key: string, evidence_refs: list<string>}>} */
    private function vectorElements(VectorGeometryData $vector): array
    {
        $elements = [];
        $issues = [];
        foreach ($vector->entities as $entity) {
            $reference = 'vector:'.$entity['handle'];
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
            $elements[] = new FusedGeometryElementData(
                'vector-'.strtolower($entity['handle']), $geometryType, $geometry, 'vector', $reference,
                $vector->sourceFingerprint, $page, 'source_units_v1', $vector->runtimeVersion,
                $vector->runtimeVersion, 1.0, [], $transform,
            );
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
