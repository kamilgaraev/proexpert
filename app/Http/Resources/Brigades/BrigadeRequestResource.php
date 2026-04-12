<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeRequest */
class BrigadeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contractor_organization_id' => $this->contractor_organization_id,
            'contractor_organization_name' => $this->contractorOrganization?->name,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'title' => $this->title,
            'description' => $this->description,
            'specialization_name' => $this->specialization_name,
            'city' => $this->city,
            'team_size_min' => $this->team_size_min,
            'team_size_max' => $this->team_size_max,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'responses_count' => $this->whenCounted('responses'),
            'responses' => BrigadeResponseResource::collection($this->whenLoaded('responses')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
