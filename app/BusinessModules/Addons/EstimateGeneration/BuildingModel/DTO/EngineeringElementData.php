<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class EngineeringElementData
{
    public string $key;

    public string $type;

    public ?array $location;

    public ?string $roomKey;

    public array $evidenceIds;

    public float $confidence;

    public string $geometryCertainty;

    public function __construct(string $key, string $type, ?array $location, ?string $roomKey, array $evidenceIds, float $confidence, string $geometryCertainty)
    {
        $this->key = BuildingModelSchema::key($key, 'Engineering element key');
        if (! in_array($type, ['outlet', 'switch', 'light', 'water_point', 'sewer_point', 'heating_point', 'ventilation_point', 'route'], true)) {
            throw new InvalidArgumentException('Engineering element type is invalid.');
        }
        $this->type = $type;
        $this->location = BuildingModelSchema::nullablePoint($location, 'Engineering element location');
        $this->roomKey = $roomKey === null ? null : BuildingModelSchema::key($roomKey, 'Engineering room key');
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->confidence = BuildingModelSchema::confidence($confidence);
        $this->geometryCertainty = BuildingModelSchema::certainty($geometryCertainty);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'type' => $this->type, 'location' => $this->location, 'room_key' => $this->roomKey, 'evidence_ids' => $this->evidenceIds, 'confidence' => $this->confidence, 'geometry_certainty' => $this->geometryCertainty];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['key', 'type', 'location', 'room_key', 'evidence_ids', 'confidence', 'geometry_certainty']);

        return BuildingModelSchema::typed(static fn (): self => new self($data['key'], $data['type'], $data['location'], $data['room_key'], $data['evidence_ids'], $data['confidence'], $data['geometry_certainty']));
    }
}
