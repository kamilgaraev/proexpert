<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'work_type_category' => $this->work_type_category,
            'is_public' => $this->is_public,
            'usage_count' => $this->usage_count,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            'organization' => $this->whenLoaded('organization'),
            'created_by' => $this->whenLoaded('createdBy'),
        ];
    }
}

