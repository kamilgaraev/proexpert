<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class FloorData
{
    public string $key;

    public ?float $elevationM;

    public ?float $heightM;

    public array $rooms;

    public array $walls;

    public array $openings;

    public array $engineeringElements;

    public array $evidenceIds;

    public float $confidence;

    public string $geometryCertainty;

    public function __construct(
        string $key,
        ?float $elevationM,
        ?float $heightM,
        array $rooms,
        array $walls,
        array $openings,
        array $engineeringElements,
        array $evidenceIds,
        float $confidence,
        string $geometryCertainty,
    ) {
        $this->key = BuildingModelSchema::key($key, 'Floor key');
        $this->elevationM = BuildingModelSchema::nullableMetric($elevationM, 'Floor elevation');
        $this->heightM = BuildingModelSchema::nullableMetric($heightM, 'Floor height', true);
        $this->rooms = self::typedList($rooms, RoomData::class, 'rooms');
        $this->walls = self::typedList($walls, WallData::class, 'walls');
        $this->openings = self::typedList($openings, OpeningData::class, 'openings');
        $this->engineeringElements = self::typedList($engineeringElements, EngineeringElementData::class, 'engineering elements');
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->confidence = BuildingModelSchema::confidence($confidence);
        $this->geometryCertainty = BuildingModelSchema::certainty($geometryCertainty);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'elevation_m' => $this->elevationM,
            'height_m' => $this->heightM,
            'rooms' => array_map(static fn (RoomData $value): array => $value->toArray(), $this->rooms),
            'walls' => array_map(static fn (WallData $value): array => $value->toArray(), $this->walls),
            'openings' => array_map(static fn (OpeningData $value): array => $value->toArray(), $this->openings),
            'engineering_elements' => array_map(static fn (EngineeringElementData $value): array => $value->toArray(), $this->engineeringElements),
            'evidence_ids' => $this->evidenceIds,
            'confidence' => $this->confidence,
            'geometry_certainty' => $this->geometryCertainty,
        ];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['key', 'elevation_m', 'height_m', 'rooms', 'walls', 'openings', 'engineering_elements', 'evidence_ids', 'confidence', 'geometry_certainty']);
        foreach (['rooms', 'walls', 'openings', 'engineering_elements'] as $key) {
            if (! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw new InvalidArgumentException("Floor {$key} must be a list.");
            }
        }

        return BuildingModelSchema::typed(static fn (): self => new self(
            $data['key'],
            $data['elevation_m'],
            $data['height_m'],
            array_map(static fn (array $item): RoomData => RoomData::fromArray($item), $data['rooms']),
            array_map(static fn (array $item): WallData => WallData::fromArray($item), $data['walls']),
            array_map(static fn (array $item): OpeningData => OpeningData::fromArray($item), $data['openings']),
            array_map(static fn (array $item): EngineeringElementData => EngineeringElementData::fromArray($item), $data['engineering_elements']),
            $data['evidence_ids'],
            $data['confidence'],
            $data['geometry_certainty'],
        ));
    }

    private static function typedList(array $values, string $class, string $field): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException("Floor {$field} must be a list.");
        }
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException("Floor {$field} contains an invalid element.");
            }
        }
        usort($values, static fn (object $left, object $right): int => $left->key <=> $right->key);

        return array_values($values);
    }
}
