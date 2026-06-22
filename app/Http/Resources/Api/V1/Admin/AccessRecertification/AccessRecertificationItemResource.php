<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AccessRecertification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccessRecertificationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $evidence = $this->evidence_snapshot ?? [];
        $permissions = $this->permission_snapshot ?? [];

        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'subject' => $this->whenLoaded('subject', fn (): ?array => $this->subject ? [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
            ] : [
                'id' => $this->subject_user_id,
                'name' => null,
            ]),
            'assignment_id' => $this->assignment_id,
            'role_slug' => $this->role_slug,
            'role_type' => $this->role_type,
            'role_label' => $this->role_label,
            'role_context' => [
                'id' => $this->role_context_id,
                'type' => $this->role_context_type,
                'resource_id' => $this->role_context_resource_id,
                'label' => $this->role_context_label,
            ],
            'permission_summary' => [
                'total' => count($permissions),
                'high_risk' => $this->risk_snapshot['high_risk_permissions'] ?? [],
            ],
            'risk' => $this->risk_snapshot ?? [],
            'risk_level' => $this->risk_level,
            'status' => $this->status,
            'evidence' => [
                'assignment_id' => $evidence['assignment_id'] ?? null,
                'role_slug' => $evidence['role_slug'] ?? null,
                'role_label' => $evidence['role_label'] ?? null,
                'context_type' => $evidence['context_type'] ?? null,
                'context_resource_id' => $evidence['context_resource_id'] ?? null,
                'permissions_count' => count($evidence['permissions'] ?? []),
                'captured_at' => $evidence['captured_at'] ?? null,
            ],
            'latest_decision' => $this->whenLoaded('latestDecision', fn (): ?array => $this->latestDecision ? [
                'id' => $this->latestDecision->id,
                'decision' => $this->latestDecision->decision,
                'reason' => $this->latestDecision->reason,
                'valid_until' => $this->latestDecision->valid_until?->toISOString(),
                'next_review_at' => $this->latestDecision->next_review_at?->toISOString(),
                'created_at' => $this->latestDecision->created_at?->toISOString(),
            ] : null),
            'due_at' => $this->due_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'next_review_at' => $this->next_review_at?->toISOString(),
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
