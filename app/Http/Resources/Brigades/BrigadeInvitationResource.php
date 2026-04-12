<?php

declare(strict_types=1);

namespace App\Http\Resources\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BrigadeInvitation */
class BrigadeInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brigade_id' => $this->brigade_id,
            'project_id' => $this->project_id,
            'contractor_organization_id' => $this->contractor_organization_id,
            'contractor_organization_name' => $this->contractorOrganization?->name,
            'project_name' => $this->project?->name,
            'message' => $this->message,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
