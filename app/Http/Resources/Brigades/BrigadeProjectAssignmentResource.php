<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeProjectAssignment */
class BrigadeProjectAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brigade_id' => $this->brigade_id,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'contractor_organization_id' => $this->contractor_organization_id,
            'contractor_organization_name' => $this->contractorOrganization?->name,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'notes' => $this->notes,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'brigade' => new BrigadeProfileResource($this->whenLoaded('brigade')),
        ];
    }
}
