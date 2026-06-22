<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AccessRecertification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccessRecertificationCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'risk_mode' => $this->risk_mode,
            'scope' => $this->scope ?? [],
            'owner' => $this->whenLoaded('owner', fn (): ?array => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ] : null),
            'escalation_user' => $this->whenLoaded('escalationUser', fn (): ?array => $this->escalationUser ? [
                'id' => $this->escalationUser->id,
                'name' => $this->escalationUser->name,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'counts' => [
                'items' => (int) ($this->items_count ?? 0),
                'pending_items' => (int) ($this->pending_items_count ?? 0),
                'overdue_items' => (int) ($this->overdue_items_count ?? 0),
                'pending_revocations' => (int) ($this->pending_revocations_count ?? 0),
                'requested_exceptions' => (int) ($this->requested_exceptions_count ?? 0),
            ],
            'snapshot_hash' => $this->snapshot_hash,
            'correlation_id' => $this->correlation_id,
            'starts_at' => $this->starts_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
