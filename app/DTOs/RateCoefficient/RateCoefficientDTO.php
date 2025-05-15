<?php

namespace App\DTOs\RateCoefficient;

use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use Carbon\Carbon;

class RateCoefficientDTO
{
    public function __construct(
        public readonly string $name,
        public readonly float $value,
        public readonly RateCoefficientTypeEnum $type,
        public readonly RateCoefficientAppliesToEnum $applies_to,
        public readonly RateCoefficientScopeEnum $scope,
        public readonly ?int $organization_id = null, // Будет установлен в сервисе
        public readonly ?string $code = null,
        public readonly ?string $description = null,
        public readonly bool $is_active = true,
        public readonly ?Carbon $valid_from = null,
        public readonly ?Carbon $valid_to = null,
        public readonly ?array $conditions = null,
        public readonly ?int $id = null
    ) {}

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'value' => $this->value,
            'type' => $this->type->value,
            'applies_to' => $this->applies_to->value,
            'scope' => $this->scope->value,
            'is_active' => $this->is_active,
        ];

        if ($this->organization_id !== null) {
            $data['organization_id'] = $this->organization_id;
        }
        if ($this->code !== null) {
            $data['code'] = $this->code;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->valid_from !== null) {
            $data['valid_from'] = $this->valid_from->toDateString();
        }
        if ($this->valid_to !== null) {
            $data['valid_to'] = $this->valid_to->toDateString();
        }
        if ($this->conditions !== null) {
            $data['conditions'] = $this->conditions; // json_encode будет в модели/репозитории при сохранении
        }

        return $data;
    }
} 