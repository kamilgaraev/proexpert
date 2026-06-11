<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use App\BusinessModules\Features\Crm\Http\Resources\Concerns\ResolvesCrmResourceState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmContactResource extends JsonResource
{
    use ResolvesCrmResourceState;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ]),
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->userSummary($this->owner)),
            'source' => $this->whenLoaded('source', fn () => $this->referenceSummary($this->source)),
            'merged_into_id' => $this->merged_into_id,
            'full_name' => $this->full_name,
            'position' => $this->position,
            'phone' => $this->phone,
            'email' => $this->email,
            'messengers' => $this->messengers ?? [],
            'is_primary' => (bool) $this->is_primary,
            'status' => $this->status,
            'personal_data_consent_at' => $this->personal_data_consent_at,
            'notes' => $this->notes,
            'last_activity_at' => $this->last_activity_at,
            'is_archived' => $this->deleted_at !== null,
            'is_merged' => $this->merged_into_id !== null,
            'contact_points' => CrmContactPointResource::collection($this->whenLoaded('contactPoints')),
            'identities' => CrmContactIdentityResource::collection($this->whenLoaded('identities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
