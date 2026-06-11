<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use App\BusinessModules\Features\Crm\Http\Resources\Concerns\ResolvesCrmResourceState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmCompanyResource extends JsonResource
{
    use ResolvesCrmResourceState;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'owner_user_id' => $this->owner_user_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->userSummary($this->owner)),
            'source' => $this->whenLoaded('source', fn () => $this->referenceSummary($this->source)),
            'linked_organization_id' => $this->linked_organization_id,
            'linked_contractor_id' => $this->linked_contractor_id,
            'merged_into_id' => $this->merged_into_id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'company_type' => $this->company_type,
            'roles' => $this->roles ?? [],
            'status' => $this->status,
            'inn' => $this->inn,
            'kpp' => $this->kpp,
            'ogrn' => $this->ogrn,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'legal_address' => $this->legal_address,
            'actual_address' => $this->actual_address,
            'tags' => $this->tags ?? [],
            'custom_fields' => $this->custom_fields ?? [],
            'notes' => $this->notes,
            'last_activity_at' => $this->last_activity_at,
            'is_archived' => $this->deleted_at !== null,
            'is_merged' => $this->merged_into_id !== null,
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => $this->primaryContact === null ? null : new CrmContactResource($this->primaryContact)),
            'contacts' => CrmContactResource::collection($this->whenLoaded('contacts')),
            'contact_points' => CrmContactPointResource::collection($this->whenLoaded('contactPoints')),
            'identities' => CrmContactIdentityResource::collection($this->whenLoaded('identities')),
            'deals' => CrmDealResource::collection($this->whenLoaded('deals')),
            'leads' => CrmLeadResource::collection($this->whenLoaded('leads')),
            'activities' => CrmActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
