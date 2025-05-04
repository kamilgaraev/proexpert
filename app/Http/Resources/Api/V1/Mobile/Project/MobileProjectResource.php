<?php

namespace App\Http\Resources\Api\V1\Mobile\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\Project) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            // Добавить другие поля при необходимости (например, адрес)
            // 'address' => $this->resource->address,
        ];
    }
} 