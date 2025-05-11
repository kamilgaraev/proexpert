<?php

namespace App\Http\Resources\Api\V1\Mobile\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileSupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof \App\Models\Supplier) {
            return [];
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            // Можно добавить другие поля, если нужны мобильному приложению, например, contact_person или phone
            // 'contact_person' => $this->resource->contact_person,
            // 'phone' => $this->resource->phone,
        ];
    }
} 