<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\AssumptionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelDetectionData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\EngineeringElementData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\OpeningData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\WallData;
use InvalidArgumentException;

final class BuildingModelAssembler
{
    public function assemble(array $detections): NormalizedBuildingModelData
    {
        if ($detections === [] || ! array_is_list($detections)) {
            throw new InvalidArgumentException('Building model detections must be a non-empty list.');
        }
        foreach ($detections as $detection) {
            if (! $detection instanceof BuildingModelDetectionData) {
                throw new InvalidArgumentException('Building model detection is invalid.');
            }
        }
        usort($detections, static fn (BuildingModelDetectionData $left, BuildingModelDetectionData $right): int => $left->producerVersion <=> $right->producerVersion);
        $scaleStatus = $detections[0]->scaleStatus;
        $scale = $detections[0]->scaleMetersPerUnit;
        $assumptions = [];
        $scaleEvidence = [];
        foreach ($detections as $detection) {
            $scaleEvidence = [...$scaleEvidence, ...$detection->evidenceIds];
            if ($detection->scaleStatus !== $scaleStatus || $detection->scaleMetersPerUnit !== $scale) {
                $assumptions['scale_conflict'] = true;
            }
        }
        $hasScaleConflict = isset($assumptions['scale_conflict']);
        if ($hasScaleConflict) {
            $scaleStatus = 'unknown';
            $scale = null;
        }

        $floors = [];
        $conflicts = [];
        foreach ($detections as $detection) {
            foreach ($detection->floors as $floor) {
                if ($hasScaleConflict) {
                    $floor = $this->withoutMetricGeometry($floor);
                }
                BuildingModelSchema::assertScaleCertainty(
                    $scaleStatus,
                    $floor->geometryCertainty,
                    $floor->elevationM !== null || $floor->heightM !== null,
                );
                if (! isset($floors[$floor->key])) {
                    $floors[$floor->key] = $floor;

                    continue;
                }
                $floors[$floor->key] = $this->mergeFloor($floors[$floor->key], $floor, $conflicts);
            }
        }
        ksort($floors, SORT_STRING);
        ksort($conflicts, SORT_STRING);
        $typedAssumptions = [];
        if ($scaleStatus !== 'confirmed') {
            if ($floors === []) {
                throw new InvalidArgumentException('Unconfirmed scale requires an affected floor.');
            }
            $typedAssumptions[] = new AssumptionData(
                $scaleStatus === 'estimated' ? 'scale_estimated' : 'scale_missing',
                'blocking',
                array_keys($floors),
                $scaleEvidence,
                true,
            );
        }
        if ($hasScaleConflict) {
            if ($floors === []) {
                throw new InvalidArgumentException('Scale conflict requires an affected floor.');
            }
            $typedAssumptions[] = new AssumptionData('scale_conflict', 'blocking', array_keys($floors), $scaleEvidence, true);
        }
        foreach ($conflicts as $key => $evidenceIds) {
            $typedAssumptions[] = new AssumptionData('geometry_conflict', 'blocking', [$key], $evidenceIds, true);
        }

        return new NormalizedBuildingModelData(
            'm',
            $scaleStatus,
            $scale,
            array_values($floors),
            $typedAssumptions,
            'building-model:v1',
        );
    }

    private function withoutMetricGeometry(FloorData $floor): FloorData
    {
        return new FloorData(
            $floor->key,
            null,
            null,
            array_map(static fn (RoomData $room): RoomData => new RoomData($room->key, $room->name, null, $room->evidenceIds, $room->confidence, 'unknown'), $floor->rooms),
            array_map(static fn (WallData $wall): WallData => new WallData($wall->key, null, null, null, null, $wall->evidenceIds, $wall->confidence, 'unknown'), $floor->walls),
            array_map(static fn (OpeningData $opening): OpeningData => new OpeningData($opening->key, $opening->wallKey, $opening->type, null, null, null, $opening->evidenceIds, $opening->confidence, 'unknown'), $floor->openings),
            array_map(static fn (EngineeringElementData $element): EngineeringElementData => new EngineeringElementData($element->key, $element->type, null, $element->roomKey, $element->evidenceIds, $element->confidence, 'unknown'), $floor->engineeringElements),
            $floor->evidenceIds,
            $floor->confidence,
            'unknown',
        );
    }

    private function mergeFloor(FloorData $left, FloorData $right, array &$conflicts): FloorData
    {
        $leftScalar = [$left->elevationM, $left->heightM, $left->geometryCertainty];
        $rightScalar = [$right->elevationM, $right->heightM, $right->geometryCertainty];
        if ($leftScalar !== $rightScalar) {
            $this->recordConflict($conflicts, $left->key, $left->evidenceIds, $right->evidenceIds);
        }
        $scalar = BuildingModelSchema::canonicalJson($leftScalar) <= BuildingModelSchema::canonicalJson($rightScalar) ? $left : $right;

        return new FloorData(
            $left->key,
            $scalar->elevationM,
            $scalar->heightM,
            $this->mergeElements($left->rooms, $right->rooms, $conflicts),
            $this->mergeElements($left->walls, $right->walls, $conflicts),
            $this->mergeElements($left->openings, $right->openings, $conflicts),
            $this->mergeElements($left->engineeringElements, $right->engineeringElements, $conflicts),
            array_values(array_unique([...$left->evidenceIds, ...$right->evidenceIds])),
            min($left->confidence, $right->confidence),
            $scalar->geometryCertainty,
        );
    }

    private function mergeElements(array $left, array $right, array &$conflicts): array
    {
        $merged = [];
        foreach ([...$left, ...$right] as $element) {
            if (! isset($merged[$element->key])) {
                $merged[$element->key] = $element;

                continue;
            }
            $existingJson = BuildingModelSchema::canonicalJson($merged[$element->key]->toArray());
            $newJson = BuildingModelSchema::canonicalJson($element->toArray());
            if ($existingJson !== $newJson) {
                $this->recordConflict($conflicts, $element->key, $merged[$element->key]->evidenceIds, $element->evidenceIds);
                if ($newJson < $existingJson) {
                    $merged[$element->key] = $element;
                }
            }
        }
        ksort($merged, SORT_STRING);

        return array_values($merged);
    }

    private function recordConflict(array &$conflicts, string $key, array $leftEvidence, array $rightEvidence): void
    {
        $conflicts[$key] = array_values(array_unique([...$conflicts[$key] ?? [], ...$leftEvidence, ...$rightEvidence]));
        sort($conflicts[$key], SORT_NUMERIC);
    }
}
