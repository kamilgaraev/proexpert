<?php

namespace App\Http\Resources\Api\V1\Admin\Material;

use Illuminate\Http\Resources\Json\JsonResource;

class MaterialMiniResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Можно добавить другие поля, если это необходимо для "мини" представления
            // например, 'measurement_unit' => $this->whenLoaded('measurementUnit', $this->measurementUnit->short_name),
        ];
    }
} 