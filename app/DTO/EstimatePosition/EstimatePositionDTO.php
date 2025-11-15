<?php

namespace App\DTO\EstimatePosition;

class EstimatePositionDTO
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $organizationId,
        public readonly ?int $categoryId,
        public readonly string $name,
        public readonly string $code,
        public readonly ?string $description,
        public readonly string $itemType,
        public readonly int $measurementUnitId,
        public readonly ?int $workTypeId,
        public readonly float $unitPrice,
        public readonly ?float $directCosts,
        public readonly ?float $overheadPercent,
        public readonly ?float $profitPercent,
        public readonly bool $isActive,
        public readonly int $createdByUserId,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            organizationId: $data['organization_id'],
            categoryId: $data['category_id'] ?? null,
            name: $data['name'],
            code: $data['code'],
            description: $data['description'] ?? null,
            itemType: $data['item_type'],
            measurementUnitId: $data['measurement_unit_id'],
            workTypeId: $data['work_type_id'] ?? null,
            unitPrice: (float) $data['unit_price'],
            directCosts: isset($data['direct_costs']) ? (float) $data['direct_costs'] : null,
            overheadPercent: isset($data['overhead_percent']) ? (float) $data['overhead_percent'] : null,
            profitPercent: isset($data['profit_percent']) ? (float) $data['profit_percent'] : null,
            isActive: $data['is_active'] ?? true,
            createdByUserId: $data['created_by_user_id'],
            metadata: $data['metadata'] ?? null,
        );
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId,
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'item_type' => $this->itemType,
            'measurement_unit_id' => $this->measurementUnitId,
            'work_type_id' => $this->workTypeId,
            'unit_price' => $this->unitPrice,
            'direct_costs' => $this->directCosts,
            'overhead_percent' => $this->overheadPercent,
            'profit_percent' => $this->profitPercent,
            'is_active' => $this->isActive,
            'created_by_user_id' => $this->createdByUserId,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Преобразовать в массив для создания записи (без id)
     */
    public function toCreateArray(): array
    {
        $data = $this->toArray();
        unset($data['id']);
        return array_filter($data, fn($value) => !is_null($value));
    }

    /**
     * Преобразовать в массив для обновления записи (только заполненные поля)
     */
    public function toUpdateArray(): array
    {
        return array_filter($this->toArray(), fn($value) => !is_null($value));
    }
}

