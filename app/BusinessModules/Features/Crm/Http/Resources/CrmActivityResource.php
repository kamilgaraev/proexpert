<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use App\BusinessModules\Features\Crm\Http\Resources\Concerns\ResolvesCrmResourceState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmActivityResource extends JsonResource
{
    use ResolvesCrmResourceState;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'owner_user_id' => $this->owner_user_id,
            'company_id' => $this->company_id,
            'contact_id' => $this->contact_id,
            'lead_id' => $this->lead_id,
            'deal_id' => $this->deal_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->userSummary($this->owner)),
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'full_name' => $this->contact->full_name,
            ]),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead === null ? null : [
                'id' => $this->lead->id,
                'title' => $this->lead->title,
            ]),
            'deal' => $this->whenLoaded('deal', fn () => $this->deal === null ? null : [
                'id' => $this->deal->id,
                'title' => $this->deal->title,
            ]),
            'type' => $this->type,
            'direction' => $this->direction,
            'status' => $this->status,
            'subject' => $this->subject,
            'body' => $this->body,
            'due_at' => $this->due_at,
            'completed_at' => $this->completed_at,
            'result' => $this->result,
            'is_archived' => $this->deleted_at !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
