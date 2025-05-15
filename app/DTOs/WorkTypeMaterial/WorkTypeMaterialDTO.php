<?php

namespace App\DTOs\WorkTypeMaterial;

class WorkTypeMaterialDTO
{
    public function __construct(
        public readonly int $material_id,
        public readonly float $default_quantity,
        public readonly ?int $organization_id = null, // Будет установлен в сервисе
        public readonly ?int $work_type_id = null,    // Будет установлен из контекста роута
        public readonly ?string $notes = null,
        public readonly ?int $id = null // Для существующих записей, если нужно
    ) {}

    public function toArrayForSync(): array
    {
        $data = [
            'default_quantity' => $this->default_quantity,
        ];
        if ($this->notes !== null) {
            $data['notes'] = $this->notes;
        }
        // organization_id и work_type_id будут добавлены в сервисе перед sync
        if ($this->organization_id !== null) {
            $data['organization_id'] = $this->organization_id;
        }
        return $data;
    }
} 