<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FsnbNormDTO
{
    public function __construct(
        public string $collectionType,
        public string $code,
        public string $name,
        public ?string $unit = null,
        public ?string $section = null,
        public array $resources = [],
        public ?array $rawData = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collection_type' => $this->collectionType,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'section' => $this->section,
            'resources' => array_map(
                static fn (FsnbNormResourceDTO $resource): array => $resource->toArray(),
                $this->resources
            ),
            'raw_data' => $this->rawData,
        ];
    }
}
