<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class WallData
{
    public string $key;

    public ?array $start;

    public ?array $end;

    public ?float $thicknessM;

    public ?float $heightM;

    public array $evidenceIds;

    public float $confidence;

    public string $geometryCertainty;

    public ?string $type;

    public ?string $material;

    public function __construct(string $key, ?array $start, ?array $end, ?float $thicknessM, ?float $heightM, array $evidenceIds, float $confidence, string $geometryCertainty, ?string $type = null, ?string $material = null)
    {
        $this->key = BuildingModelSchema::key($key, 'Wall key');
        $this->start = BuildingModelSchema::nullablePoint($start, 'Wall start');
        $this->end = BuildingModelSchema::nullablePoint($end, 'Wall end');
        if (($this->start === null) !== ($this->end === null)) {
            throw new InvalidArgumentException('Wall endpoints must both be present or absent.');
        }
        if ($this->start !== null && $this->start === $this->end) {
            throw new InvalidArgumentException('Wall length must be positive.');
        }
        $this->thicknessM = BuildingModelSchema::nullableMetric($thicknessM, 'Wall thickness', true);
        $this->heightM = BuildingModelSchema::nullableMetric($heightM, 'Wall height', true);
        $this->evidenceIds = BuildingModelSchema::evidenceIds($evidenceIds);
        $this->confidence = BuildingModelSchema::confidence($confidence);
        $this->geometryCertainty = BuildingModelSchema::certainty($geometryCertainty);
        $this->type = BuildingModelSchema::nullableLabel($type, 'Wall type');
        $this->material = BuildingModelSchema::nullableLabel($material, 'Wall material');
    }

    public function length(): ?float
    {
        return $this->start === null ? null : hypot($this->end[0] - $this->start[0], $this->end[1] - $this->start[1]);
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'start' => $this->start, 'end' => $this->end, 'thickness_m' => $this->thicknessM, 'height_m' => $this->heightM, 'type' => $this->type, 'material' => $this->material, 'evidence_ids' => $this->evidenceIds, 'confidence' => $this->confidence, 'geometry_certainty' => $this->geometryCertainty];
    }

    public static function fromArray(array $data): self
    {
        $legacy = ! array_key_exists('type', $data) && ! array_key_exists('material', $data);
        BuildingModelSchema::exactKeys($data, $legacy
            ? ['key', 'start', 'end', 'thickness_m', 'height_m', 'evidence_ids', 'confidence', 'geometry_certainty']
            : ['key', 'start', 'end', 'thickness_m', 'height_m', 'type', 'material', 'evidence_ids', 'confidence', 'geometry_certainty']);

        return BuildingModelSchema::typed(static fn (): self => new self($data['key'], $data['start'], $data['end'], $data['thickness_m'], $data['height_m'], $data['evidence_ids'], $data['confidence'], $data['geometry_certainty'], $data['type'] ?? null, $data['material'] ?? null));
    }
}
