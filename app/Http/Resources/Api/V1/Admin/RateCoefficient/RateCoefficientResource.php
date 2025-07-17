<?php

namespace App\Http\Resources\Api\V1\Admin\RateCoefficient;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RateCoefficientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'value' => (float) $this->value,
            'type' => $this->type,
            'applies_to' => $this->applies_to,
            'scope' => $this->scope,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'conditions' => $this->conditions,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
} 