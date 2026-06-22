<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AccessRecertification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccessRecertificationExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'campaign' => $this->whenLoaded('campaign', fn (): ?array => $this->campaign ? [
                'id' => $this->campaign->id,
                'name' => $this->campaign->name,
                'status' => $this->campaign->status,
            ] : null),
            'item_id' => $this->item_id,
            'decision_id' => $this->decision_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'valid_until' => $this->valid_until?->toISOString(),
            'requested_by' => $this->whenLoaded('requestedBy', fn (): ?array => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null),
            'approved_by' => $this->whenLoaded('approvedBy', fn (): ?array => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn (): ?array => $this->rejectedBy ? [
                'id' => $this->rejectedBy->id,
                'name' => $this->rejectedBy->name,
            ] : null),
            'compensating_controls' => $this->compensating_controls ?? [],
            'linked_sod_rule_ids' => $this->linked_sod_rule_ids ?? [],
            'evidence' => $this->evidence_snapshot ?? [],
            'audit_event_id' => $this->audit_event_id,
            'created_at' => $this->created_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
        ];
    }
}
