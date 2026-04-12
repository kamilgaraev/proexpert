<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeProfile */
class BrigadeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'team_size' => $this->team_size,
            'specializations' => $this->specializations->pluck('name')->values()->all(),
            'regions' => $this->regions ?? [],
            'availability_status' => $this->availability_status,
            'verification_status' => $this->verification_status,
            'rating' => (float) $this->rating,
            'completed_projects_count' => $this->completed_projects_count,
            'contact_person' => $this->contact_person,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'members' => BrigadeMemberResource::collection($this->whenLoaded('members')),
            'documents' => BrigadeDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
