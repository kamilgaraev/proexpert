<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use App\BusinessModules\Features\Crm\Http\Resources\Concerns\ResolvesCrmResourceState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmLeadResource extends JsonResource
{
    use ResolvesCrmResourceState;

    public function toArray(Request $request): array
    {
        $canViewAmounts = $this->canViewAmounts($request);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'company_id' => $this->company_id,
            'contact_id' => $this->contact_id,
            'owner_user_id' => $this->owner_user_id,
            'converted_deal_id' => $this->converted_deal_id,
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ]),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
                'phone' => $this->contact->phone,
                'email' => $this->contact->email,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => $this->userSummary($this->owner)),
            'source' => $this->whenLoaded('source', fn () => $this->referenceSummary($this->source)),
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'estimated_amount' => $canViewAmounts ? $this->estimated_amount : null,
            'amount_visible' => $canViewAmounts,
            'expected_start_date' => $this->expected_start_date,
            'need_description' => $this->need_description,
            'utm' => $this->utm ?? [],
            'lost_reason' => $this->lost_reason,
            'converted_at' => $this->converted_at,
            'is_archived' => $this->deleted_at !== null,
            'activities' => CrmActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
