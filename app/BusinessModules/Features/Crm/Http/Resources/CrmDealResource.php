<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use App\BusinessModules\Features\Crm\Http\Resources\Concerns\ResolvesCrmResourceState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmDealResource extends JsonResource
{
    use ResolvesCrmResourceState;

    public function toArray(Request $request): array
    {
        $canViewAmounts = $this->canViewAmounts($request);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'company_id' => $this->company_id,
            'primary_contact_id' => $this->primary_contact_id,
            'lead_id' => $this->lead_id,
            'owner_user_id' => $this->owner_user_id,
            'project_id' => $this->project_id,
            'contract_id' => $this->contract_id,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ]),
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => $this->primaryContact === null ? null : [
                'id' => $this->primaryContact->id,
                'full_name' => $this->primaryContact->full_name,
                'phone' => $this->primaryContact->phone,
                'email' => $this->primaryContact->email,
            ]),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead === null ? null : [
                'id' => $this->lead->id,
                'title' => $this->lead->title,
                'status' => $this->lead->status,
            ]),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'contract_number' => $this->project->contract_number,
                'status' => $this->project->status ?? null,
            ]),
            'contract' => $this->whenLoaded('contract', fn () => $this->contract === null ? null : [
                'id' => $this->contract->id,
                'number' => $this->contract->number,
                'status' => $this->contract->status ?? null,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => $this->userSummary($this->owner)),
            'pipeline' => $this->whenLoaded('pipeline', fn () => $this->referenceSummary($this->pipeline)),
            'stage' => $this->whenLoaded('stage', fn () => $this->referenceSummary($this->stage)),
            'source' => $this->whenLoaded('source', fn () => $this->referenceSummary($this->source)),
            'title' => $this->title,
            'pipeline_code' => $this->pipeline_code,
            'stage_code' => $this->stage_code,
            'status' => $this->status,
            'amount' => $canViewAmounts ? $this->amount : null,
            'currency' => $this->currency,
            'amount_visible' => $canViewAmounts,
            'probability' => $this->probability,
            'expected_close_at' => $this->expected_close_at,
            'won_at' => $this->won_at,
            'lost_at' => $this->lost_at,
            'lost_reason' => $this->lost_reason,
            'next_activity_at' => $this->next_activity_at,
            'custom_fields' => $this->custom_fields ?? [],
            'is_archived' => $this->deleted_at !== null,
            'activities' => CrmActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
