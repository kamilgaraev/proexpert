<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class BuildingModelDetectionData
{
    public string $producerVersion;

    public string $scaleStatus;

    public ?float $scaleMetersPerUnit;

    public array $floors;

    public array $evidenceIds;

    public function __construct(string $producerVersion, string $scaleStatus, ?float $scaleMetersPerUnit, array $floors, array $evidenceIds)
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}:v[1-9][0-9]*$/', $producerVersion) !== 1) {
            throw new InvalidArgumentException('Detection producer version is invalid.');
        }
        if (! in_array($scaleStatus, ['confirmed', 'estimated', 'unknown'], true)) {
            throw new InvalidArgumentException('Detection scale status is invalid.');
        }
        $scale = BuildingModelSchema::nullableMetric($scaleMetersPerUnit, 'Detection scale', true);
        if (($scaleStatus === 'unknown') !== ($scale === null) || ! array_is_list($floors)) {
            throw new InvalidArgumentException('Detection scale or floors are invalid.');
        }
        foreach ($floors as $floor) {
            if (! $floor instanceof FloorData) {
                throw new InvalidArgumentException('Detection floor is invalid.');
            }
        }
        usort($floors, static fn (FloorData $left, FloorData $right): int => $left->key <=> $right->key);
        $this->producerVersion = $producerVersion;
        $this->scaleStatus = $scaleStatus;
        $this->scaleMetersPerUnit = $scale;
        $this->floors = array_values($floors);
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
    }
}
