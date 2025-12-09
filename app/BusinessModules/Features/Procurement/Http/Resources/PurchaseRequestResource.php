<?php

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'site_request_id' => $this->site_request_id,
            'assigned_to' => $this->assigned_to,
            'request_number' => $this->request_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_approved' => $this->canBeApproved(),
            'can_be_rejected' => $this->canBeRejected(),
            'site_request' => $this->whenLoaded('siteRequest', fn() => [
                'id' => $this->siteRequest->id,
                'title' => $this->siteRequest->title,
                'request_number' => $this->siteRequest->id, // Часто используется как номер
                'material_name' => $this->siteRequest->material_name,
                'material_quantity' => $this->siteRequest->material_quantity,
                'material_unit' => $this->siteRequest->material_unit,
                'priority' => $this->siteRequest->priority->value,
                'priority_label' => $this->siteRequest->priority->label(),
                'required_date' => $this->siteRequest->required_date?->format('Y-m-d'),
                'project' => $this->siteRequest->project ? [
                    'id' => $this->siteRequest->project->id,
                    'name' => $this->siteRequest->project->name,
                ] : null,
            ]),
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'purchase_orders' => $this->whenLoaded('purchaseOrders', fn() => PurchaseOrderResource::collection($this->purchaseOrders)),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

