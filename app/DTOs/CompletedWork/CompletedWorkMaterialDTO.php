<?php

namespace App\DTOs\CompletedWork;

class CompletedWorkMaterialDTO
{
    public function __construct(
        public readonly int $material_id,
        public readonly float $quantity,
        public readonly ?float $unit_price,
        public readonly ?float $total_amount,
        public readonly ?string $notes,
        public readonly ?string $material_name = null,
        public readonly ?string $measurement_unit = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            material_id: $data['material_id'],
            quantity: (float)$data['quantity'],
            unit_price: isset($data['unit_price']) ? (float)$data['unit_price'] : null,
            total_amount: isset($data['total_amount']) ? (float)$data['total_amount'] : null,
            notes: $data['notes'] ?? null,
            material_name: $data['material_name'] ?? null,
            measurement_unit: $data['measurement_unit'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'material_id' => $this->material_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,
        ];
    }
} 