<?php

namespace App\Http\Resources\Api\V1\Mobile\WorkType;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileWorkTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\WorkType) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            // Предполагаем, что у модели WorkType есть отношение measurementUnit,
            // а у MeasurementUnit есть поля name и symbol.
            'measurement_unit_id' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->id),
            'measurement_unit_name' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->name),
            'measurement_unit_symbol' => $this->whenLoaded('measurementUnit', fn() => $this->resource->measurementUnit?->symbol),
            // Можно добавить и другие поля, если они нужны, например, категория
            // 'category' => $this->resource->category,
        ];
    }
} 