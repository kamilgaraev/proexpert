<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AccessRecertification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccessRecertificationDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'item_id' => $this->item_id,
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'decision' => $this->decision,
            'reason' => $this->reason,
            'valid_until' => $this->valid_until?->toISOString(),
            'next_review_at' => $this->next_review_at?->toISOString(),
            'revoke_executor_user_id' => $this->revoke_executor_user_id,
            'compensating_controls' => $this->compensating_controls ?? [],
            'linked_sod_rule_ids' => $this->linked_sod_rule_ids ?? [],
            'evidence' => $this->evidence_snapshot ?? [],
            'audit_event_id' => $this->audit_event_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
