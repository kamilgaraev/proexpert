<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyWorkPermit */
final class SafetyWorkPermitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyWorkPermit $permit */
        $permit = $this->resource;
        $actions = match ($permit->status) {
            'draft' => ['submit'],
            'pending_approval' => ['approve', 'reject'],
            'approved' => ['activate', 'suspend', 'close'],
            'active' => ['suspend', 'close'],
            'suspended' => ['activate', 'close'],
            default => [],
        };

        return [
            'id' => $permit->id,
            'organization_id' => $permit->organization_id,
            'project_id' => $permit->project_id,
            'created_by_user_id' => $permit->created_by_user_id,
            'responsible_user_id' => $permit->responsible_user_id,
            'approved_by_user_id' => $permit->approved_by_user_id,
            'rejected_by_user_id' => $permit->rejected_by_user_id,
            'suspended_by_user_id' => $permit->suspended_by_user_id,
            'closed_by_user_id' => $permit->closed_by_user_id,
            'permit_number' => $permit->permit_number,
            'title' => $permit->title,
            'permit_type' => $permit->permit_type,
            'location_name' => $permit->location_name,
            'risk_level' => $permit->risk_level,
            'valid_from' => $permit->valid_from?->toIso8601String(),
            'valid_until' => $permit->valid_until?->toIso8601String(),
            'required_controls' => $permit->required_controls ?? [],
            'status' => $permit->status,
            'status_label' => trans_message("safety_management.permit_statuses.{$permit->status}"),
            'submitted_at' => $permit->submitted_at?->toIso8601String(),
            'approved_at' => $permit->approved_at?->toIso8601String(),
            'activated_at' => $permit->activated_at?->toIso8601String(),
            'rejected_at' => $permit->rejected_at?->toIso8601String(),
            'suspended_at' => $permit->suspended_at?->toIso8601String(),
            'closed_at' => $permit->closed_at?->toIso8601String(),
            'approval_comment' => $permit->approval_comment,
            'rejection_reason' => $permit->rejection_reason,
            'suspension_reason' => $permit->suspension_reason,
            'close_comment' => $permit->close_comment,
            'workflow_summary' => [
                'stage' => $permit->status,
                'status' => $permit->status,
                'stage_label' => trans_message("safety_management.permit_statuses.{$permit->status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("safety_management.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => $this->problemFlags($permit),
                'warnings' => [],
            ],
            'problem_flags' => $this->problemFlags($permit),
            'available_actions' => $actions,
            'project' => $this->whenLoaded('project', fn () => $permit->project ? [
                'id' => $permit->project->id,
                'name' => $permit->project->name,
            ] : null),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $permit->responsibleUser ? [
                'id' => $permit->responsibleUser->id,
                'name' => $permit->responsibleUser->name,
            ] : null),
            'metadata' => $permit->metadata,
            'created_at' => $permit->created_at?->toIso8601String(),
            'updated_at' => $permit->updated_at?->toIso8601String(),
        ];
    }

    private function problemFlags(SafetyWorkPermit $permit): array
    {
        if ($permit->status !== 'closed' && $permit->valid_until->isPast()) {
            return [[
                'code' => 'permit_expired',
                'severity' => 'critical',
                'message' => trans_message('safety_management.problem_flags.permit_expired'),
            ]];
        }

        return [];
    }
}
