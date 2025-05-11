<?php

namespace App\Http\Resources\Api\V1\Mobile\Material;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileMaterialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\Material) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'code' => $this->resource->code,
            // Предполагаем, что у модели Material есть отношение measurementUnit
            'measurement_unit_id' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->id),
            'measurement_unit_name' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->name),
            'measurement_unit_symbol' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->symbol),
            'category' => $this->resource->category,
            // 'default_price' => $this->resource->default_price, // Возможно, не нужно для простого выбора
            // 'is_active' => (bool) $this->resource->is_active, // Мобильное приложение, вероятно, должно получать только активные
        ];
    }
} 