<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

final readonly class RoomData
{
    public string $key;

    public ?string $name;

    public ?array $polygon;

    public array $evidenceIds;

    public float $confidence;

    public string $geometryCertainty;

    public function __construct(string $key, ?string $name, ?array $polygon, array $evidenceIds, float $confidence, string $geometryCertainty)
    {
        $this->key = BuildingModelSchema::key($key, 'Room key');
        $this->name = BuildingModelSchema::nullableLabel($name, 'Room name');
        $this->polygon = BuildingModelSchema::nullablePolygon($polygon);
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->confidence = BuildingModelSchema::confidence($confidence);
        $this->geometryCertainty = BuildingModelSchema::certainty($geometryCertainty);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'polygon' => $this->polygon, 'evidence_ids' => $this->evidenceIds, 'confidence' => $this->confidence, 'geometry_certainty' => $this->geometryCertainty];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['key', 'name', 'polygon', 'evidence_ids', 'confidence', 'geometry_certainty']);

        return BuildingModelSchema::typed(static fn (): self => new self($data['key'], $data['name'], $data['polygon'], $data['evidence_ids'], $data['confidence'], $data['geometry_certainty']));
    }
}
