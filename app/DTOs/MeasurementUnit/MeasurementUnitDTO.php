<?php

namespace App\DTOs\MeasurementUnit;

class MeasurementUnitDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $short_name,
        public readonly ?string $type = 'material',
        public readonly ?string $description = null,
        public readonly ?bool $is_default = false,
        // public readonly ?bool $is_system = false, // is_system не должен управляться пользователем напрямую
        public readonly ?int $organization_id = null // Будет устанавливаться в сервисе из текущего пользователя
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'short_name' => $this->short_name,
            'type' => $this->type,
            'description' => $this->description,
            'is_default' => $this->is_default,
            // 'organization_id' => $this->organization_id, // Не включаем, т.к. будет из контекста
        ];
    }
} 