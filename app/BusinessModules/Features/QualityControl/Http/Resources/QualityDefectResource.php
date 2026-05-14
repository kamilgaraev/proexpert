<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Resources;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QualityDefect */
final class QualityDefectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QualityDefect $defect */
        $defect = $this->resource;
        $workflow = app(QualityDefectWorkflowService::class)->forDefect($defect);

        return [
            'id' => $defect->id,
            'organization_id' => $defect->organization_id,
            'project_id' => $defect->project_id,
            'contractor_id' => $defect->contractor_id,
            'created_by' => $defect->created_by,
            'assigned_to' => $defect->assigned_to,
            'defect_number' => $defect->defect_number,
            'title' => $defect->title,
            'description' => $defect->description,
            'severity' => $defect->severity->value,
            'severity_label' => $defect->severity->label(),
            'severity_color' => $defect->severity->color(),
            'status' => $defect->status->value,
            'status_label' => $defect->status->label(),
            'status_color' => $defect->status->color(),
            'location_name' => $defect->location_name,
            'schedule_task_id' => $defect->schedule_task_id,
            'construction_journal_entry_id' => $defect->construction_journal_entry_id,
            'completed_work_id' => $defect->completed_work_id,
            'due_date' => $defect->due_date?->format('Y-m-d'),
            'inspection_required' => $defect->inspection_required,
            'resolved_at' => $defect->resolved_at?->toIso8601String(),
            'verified_at' => $defect->verified_at?->toIso8601String(),
            'metadata' => $defect->metadata,
            'can_be_assigned' => $defect->canBeAssigned(),
            'can_be_started' => $defect->canBeStarted(),
            'can_be_resolved' => $defect->canBeResolved(),
            'can_be_verified' => $defect->canBeVerified(),
            'workflow_summary' => $workflow->toArray(),
            'problem_flags' => $workflow->problemFlags,
            'available_actions' => $workflow->availableActions,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor ? [
                'id' => $this->contractor->id,
                'name' => $this->contractor->name,
            ] : null),
            'created_user' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'assigned_user' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
            ] : null),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'type' => $photo->type,
                'url' => $photo->url,
                'caption' => $photo->caption,
                'uploaded_by' => $photo->uploaded_by,
                'created_at' => $photo->created_at?->toIso8601String(),
            ])),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($history) => [
                'id' => $history->id,
                'from_status' => $history->from_status?->value,
                'to_status' => $history->to_status->value,
                'comment' => $history->comment,
                'changed_by' => $history->changed_by,
                'changed_at' => $history->changed_at?->toIso8601String(),
            ])),
            'created_at' => $defect->created_at->toIso8601String(),
            'updated_at' => $defect->updated_at->toIso8601String(),
        ];
    }
}
