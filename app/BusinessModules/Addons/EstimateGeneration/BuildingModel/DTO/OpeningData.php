<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class OpeningData
{
    public string $key;

    public string $wallKey;

    public string $type;

    public ?float $offsetM;

    public ?float $widthM;

    public ?float $heightM;

    public array $evidenceIds;

    public float $confidence;

    public string $geometryCertainty;

    public function __construct(string $key, string $wallKey, string $type, ?float $offsetM, ?float $widthM, ?float $heightM, array $evidenceIds, float $confidence, string $geometryCertainty)
    {
        $this->key = BuildingModelSchema::key($key, 'Opening key');
        $this->wallKey = BuildingModelSchema::key($wallKey, 'Opening wall key');
        if (! in_array($type, ['door', 'window', 'gate'], true)) {
            throw new InvalidArgumentException('Opening type is invalid.');
        }
        $this->type = $type;
        $this->offsetM = BuildingModelSchema::nullableMetric($offsetM, 'Opening offset');
        if ($this->offsetM !== null && $this->offsetM < 0) {
            throw new InvalidArgumentException('Opening offset is invalid.');
        }
        $this->widthM = BuildingModelSchema::nullableMetric($widthM, 'Opening width', true);
        $this->heightM = BuildingModelSchema::nullableMetric($heightM, 'Opening height', true);
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->confidence = BuildingModelSchema::confidence($confidence);
        $this->geometryCertainty = BuildingModelSchema::certainty($geometryCertainty);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'wall_key' => $this->wallKey, 'type' => $this->type, 'offset_m' => $this->offsetM, 'width_m' => $this->widthM, 'height_m' => $this->heightM, 'evidence_ids' => $this->evidenceIds, 'confidence' => $this->confidence, 'geometry_certainty' => $this->geometryCertainty];
    }

    public static function fromArray(array $data): self
    {
        BuildingModelSchema::exactKeys($data, ['key', 'wall_key', 'type', 'offset_m', 'width_m', 'height_m', 'evidence_ids', 'confidence', 'geometry_certainty']);

        return BuildingModelSchema::typed(static fn (): self => new self($data['key'], $data['wall_key'], $data['type'], $data['offset_m'], $data['width_m'], $data['height_m'], $data['evidence_ids'], $data['confidence'], $data['geometry_certainty']));
    }
}
