<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class NormalizedBuildingModelData
{
    public string $unit;

    public string $scaleStatus;

    public ?float $scaleMetersPerUnit;

    public array $floors;

    public array $assumptions;

    public string $modelVersion;

    public array $evidenceIds;

    public array $metrics;

    public function __construct(
        string $unit,
        string $scaleStatus,
        ?float $scaleMetersPerUnit,
        array $floors,
        array $assumptions,
        string $modelVersion,
    ) {
        if ($unit !== 'm' || $modelVersion !== 'building-model:v1') {
            throw new InvalidArgumentException('Building model schema/version is invalid.');
        }
        if (! in_array($scaleStatus, ['confirmed', 'estimated', 'unknown'], true)) {
            throw new InvalidArgumentException('Building model scale status is invalid.');
        }
        $scale = BuildingModelSchema::nullableMetric($scaleMetersPerUnit, 'Building model scale', true);
        if (($scaleStatus === 'unknown') !== ($scale === null)) {
            throw new InvalidArgumentException('Building model scale is inconsistent with scale status.');
        }
        if (! array_is_list($floors) || count($floors) > BuildingModelSchema::MAX_FLOORS) {
            throw new InvalidArgumentException('Building model floor count is invalid.');
        }
        if (! array_is_list($assumptions)) {
            throw new InvalidArgumentException('Building model assumptions must be a list.');
        }
        foreach ($floors as $floor) {
            if (! $floor instanceof FloorData) {
                throw new InvalidArgumentException('Building model contains an invalid floor.');
            }
        }
        foreach ($assumptions as $assumption) {
            if (! $assumption instanceof AssumptionData) {
                throw new InvalidArgumentException('Building model contains an invalid assumption.');
            }
        }
        $metricFloors = array_values(array_filter($floors, static fn (FloorData $floor): bool => $floor->elevationM !== null && $floor->heightM !== null));
        usort($metricFloors, static fn (FloorData $left, FloorData $right): int => $left->elevationM <=> $right->elevationM);
        for ($index = 1; $index < count($metricFloors); $index++) {
            $previous = $metricFloors[$index - 1];
            if ($metricFloors[$index]->elevationM < $previous->elevationM + $previous->heightM - 0.000001) {
                throw new InvalidArgumentException('Floor elevations and heights must be coherent.');
            }
        }
        usort($floors, static fn (FloorData $left, FloorData $right): int => $left->key <=> $right->key);
        usort($assumptions, static fn (AssumptionData $left, AssumptionData $right): int => [$left->code, $left->affectedKeys] <=> [$right->code, $right->affectedKeys]);

        $keys = [];
        $evidence = [];
        $elementCount = 0;
        foreach ($floors as $floor) {
            self::uniqueKey($floor->key, $keys);
            self::addEvidence($floor->evidenceIds, $evidence);
            $hasFloorMetric = $floor->elevationM !== null || $floor->heightM !== null;
            BuildingModelSchema::assertScaleCertainty($scaleStatus, $floor->geometryCertainty, $hasFloorMetric);
            $roomKeys = [];
            $wallKeys = [];
            foreach ($floor->rooms as $room) {
                self::uniqueKey($room->key, $keys);
                $roomKeys[$room->key] = true;
                self::addEvidence($room->evidenceIds, $evidence);
                BuildingModelSchema::assertScaleCertainty($scaleStatus, $room->geometryCertainty, $room->polygon !== null);
                $elementCount++;
            }
            foreach ($floor->walls as $wall) {
                self::uniqueKey($wall->key, $keys);
                $wallKeys[$wall->key] = $wall;
                self::addEvidence($wall->evidenceIds, $evidence);
                BuildingModelSchema::assertScaleCertainty($scaleStatus, $wall->geometryCertainty, $wall->start !== null || $wall->thicknessM !== null || $wall->heightM !== null);
                $elementCount++;
            }
            foreach ($floor->openings as $opening) {
                self::uniqueKey($opening->key, $keys);
                self::addEvidence($opening->evidenceIds, $evidence);
                if (! isset($wallKeys[$opening->wallKey])) {
                    throw new InvalidArgumentException('Opening has a dangling wall reference on its floor.');
                }
                BuildingModelSchema::assertScaleCertainty($scaleStatus, $opening->geometryCertainty, $opening->offsetM !== null || $opening->widthM !== null || $opening->heightM !== null);
                $wallLength = $wallKeys[$opening->wallKey]->length();
                if ($wallLength !== null && $opening->offsetM !== null && $opening->widthM !== null && $opening->offsetM + $opening->widthM > $wallLength + 0.000001) {
                    throw new InvalidArgumentException('Opening dimensions must fit wall length.');
                }
                if ($floor->heightM !== null && $opening->heightM !== null && $opening->heightM > $floor->heightM) {
                    throw new InvalidArgumentException('Opening height must fit floor height.');
                }
                $elementCount++;
            }
            foreach ($floor->engineeringElements as $element) {
                self::uniqueKey($element->key, $keys);
                self::addEvidence($element->evidenceIds, $evidence);
                if ($element->roomKey !== null && ! isset($roomKeys[$element->roomKey])) {
                    throw new InvalidArgumentException('Engineering element has a dangling room reference.');
                }
                BuildingModelSchema::assertScaleCertainty($scaleStatus, $element->geometryCertainty, $element->location !== null);
                $elementCount++;
            }
        }
        foreach ($assumptions as $assumption) {
            self::addEvidence($assumption->evidenceIds, $evidence);
            foreach ($assumption->affectedKeys as $key) {
                if (! isset($keys[$key])) {
                    throw new InvalidArgumentException('Assumption has a dangling affected key.');
                }
            }
        }
        $scaleBlockers = array_values(array_filter(
            $assumptions,
            static fn (AssumptionData $assumption): bool => in_array($assumption->code, ['scale_estimated', 'scale_missing', 'scale_conflict'], true),
        ));
        if ($scaleStatus === 'confirmed' && $scaleBlockers !== []) {
            throw new InvalidArgumentException('Confirmed scale contains a stale scale blocker.');
        }
        if ($scaleStatus !== 'confirmed') {
            $requiredCode = $scaleStatus === 'estimated' ? 'scale_estimated' : 'scale_missing';
            $matching = array_values(array_filter(
                $scaleBlockers,
                static fn (AssumptionData $assumption): bool => $assumption->code === $requiredCode
                    && $assumption->severity === 'blocking'
                    && $assumption->requiresConfirmation,
            ));
            if ($matching === []) {
                throw new InvalidArgumentException("Unconfirmed scale requires {$requiredCode} blocking confirmation metadata.");
            }
        }
        if ($elementCount > BuildingModelSchema::MAX_ELEMENTS) {
            throw new InvalidArgumentException('Building model element count exceeds the limit.');
        }
        ksort($evidence, SORT_NUMERIC);

        $this->unit = $unit;
        $this->scaleStatus = $scaleStatus;
        $this->scaleMetersPerUnit = $scale;
        $this->floors = array_values($floors);
        $this->assumptions = array_values($assumptions);
        $this->modelVersion = $modelVersion;
        $this->evidenceIds = array_values($evidence);
        $this->metrics = [
            'floor_count' => count($floors),
            'room_count' => array_sum(array_map(static fn (FloorData $floor): int => count($floor->rooms), $floors)),
            'wall_count' => array_sum(array_map(static fn (FloorData $floor): int => count($floor->walls), $floors)),
            'opening_count' => array_sum(array_map(static fn (FloorData $floor): int => count($floor->openings), $floors)),
            'engineering_element_count' => array_sum(array_map(static fn (FloorData $floor): int => count($floor->engineeringElements), $floors)),
            'evidence_count' => count($evidence),
            'minimum_confidence' => $this->minimumConfidence($floors),
            'complete' => $scaleStatus === 'confirmed' && $floors !== [] && $evidence !== []
                && array_filter($assumptions, static fn (AssumptionData $item): bool => $item->severity === 'blocking') === [],
        ];
        if (strlen(BuildingModelSchema::canonicalJson($this->toArray())) > BuildingModelSchema::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Building model JSON exceeds the limit.');
        }
    }

    public function toArray(): array
    {
        return [
            'model_version' => $this->modelVersion,
            'coordinate_system' => 'metric-right-handed-2d:v1',
            'unit' => $this->unit,
            'scale_status' => $this->scaleStatus,
            'scale_meters_per_unit' => $this->scaleMetersPerUnit,
            'floors' => array_map(static fn (FloorData $floor): array => $floor->toArray(), $this->floors),
            'assumptions' => array_map(static fn (AssumptionData $assumption): array => $assumption->toArray(), $this->assumptions),
            'evidence_ids' => $this->evidenceIds,
            'metrics' => $this->metrics,
        ];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['model_version', 'coordinate_system', 'unit', 'scale_status', 'scale_meters_per_unit', 'floors', 'assumptions', 'evidence_ids', 'metrics']);
        if ($data['coordinate_system'] !== 'metric-right-handed-2d:v1' || ! is_array($data['floors']) || ! array_is_list($data['floors']) || ! is_array($data['assumptions']) || ! array_is_list($data['assumptions'])) {
            throw new InvalidArgumentException('Building model coordinate/schema contract is invalid.');
        }
        $model = BuildingModelSchema::typed(static fn (): self => new self(
            $data['unit'],
            $data['scale_status'],
            $data['scale_meters_per_unit'],
            array_map(static fn (array $floor): FloorData => FloorData::fromArray($floor), $data['floors']),
            array_map(static fn (array $assumption): AssumptionData => AssumptionData::fromArray($assumption), $data['assumptions']),
            $data['model_version'],
        ));
        if ($model->evidenceIds !== $data['evidence_ids']
            || BuildingModelSchema::canonicalJson($model->metrics) !== BuildingModelSchema::canonicalJson($data['metrics'])) {
            throw new InvalidArgumentException('Building model derived evidence or metrics are inconsistent.');
        }

        return $model;
    }

    public function contentVersion(): string
    {
        return 'sha256:'.hash('sha256', BuildingModelSchema::canonicalJson($this->toArray()));
    }

    private static function uniqueKey(string $key, array &$keys): void
    {
        if (isset($keys[$key])) {
            throw new InvalidArgumentException('Building model element keys must be globally unique.');
        }
        $keys[$key] = true;
    }

    private static function addEvidence(array $ids, array &$evidence): void
    {
        foreach ($ids as $id) {
            $evidence[$id] = $id;
        }
    }

    private function minimumConfidence(array $floors): float
    {
        $values = [];
        foreach ($floors as $floor) {
            $values[] = $floor->confidence;
            foreach ([$floor->rooms, $floor->walls, $floor->openings, $floor->engineeringElements] as $elements) {
                foreach ($elements as $element) {
                    $values[] = $element->confidence;
                }
            }
        }

        return $values === [] ? 0.0 : min($values);
    }
}
