<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\Specification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'spec_date' => $this->spec_date,
            'total_amount' => (float) ($this->total_amount ?? 0),
            'status' => $this->status,
            'scope_items' => $this->scope_items,
            'attached_at' => $this->pivot?->attached_at,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 