<?php

namespace App\BusinessModules\Addons\AIEstimates\DTOs;

class MatchedPositionDTO
{
    public function __construct(
        public readonly int $catalogId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $unit,
        public readonly float $price,
        public readonly float $confidence,
        public readonly ?string $category = null,
        public readonly ?array $alternatives = null,
    ) {}

    public static function fromCatalogItem(
        object $catalogItem,
        float $confidence,
        ?array $alternatives = null
    ): self {
        return new self(
            catalogId: $catalogItem->id,
            code: $catalogItem->code ?? '',
            name: $catalogItem->name,
            unit: $catalogItem->unit,
            price: $catalogItem->price ?? 0.0,
            confidence: $confidence,
            category: $catalogItem->category?->name ?? null,
            alternatives: $alternatives,
        );
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.75;
    }

    public function toArray(): array
    {
        return [
            'catalog_id' => $this->catalogId,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'price' => $this->price,
            'confidence' => $this->confidence,
            'category' => $this->category,
            'alternatives' => $this->alternatives,
        ];
    }
}
