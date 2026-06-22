<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AccessRecertification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccessRecertificationRevocationResource extends JsonResource
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
            'subject' => $this->whenLoaded('subject', fn (): ?array => $this->subject ? [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
            ] : [
                'id' => $this->subject_user_id,
                'name' => null,
            ]),
            'role_slug' => $this->role_slug,
            'role_type' => $this->role_type,
            'status' => $this->status,
            'reason' => $this->reason,
            'executor' => $this->whenLoaded('executor', fn (): ?array => $this->executor ? [
                'id' => $this->executor->id,
                'name' => $this->executor->name,
            ] : null),
            'due_at' => $this->due_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failure_reason' => $this->failure_reason,
            'audit_event_id' => $this->audit_event_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
