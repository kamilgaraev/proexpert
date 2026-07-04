<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyEmployeeRequirement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyEmployeeRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        if (!$record instanceof SafetyEmployeeRequirement) {
            return [];
        }

        return [
            'id' => $record->id,
            'organization_id' => $record->organization_id,
            'employee_id' => $record->employee_id,
            'user_id' => $record->user_id,
            'project_id' => $record->project_id,
            'work_type_id' => $record->work_type_id,
            'work_category' => $record->work_category,
            'requirement_code' => $record->requirement_code,
            'requirement_type' => $record->requirement_type,
            'source_type' => $record->source_type,
            'source_id' => $record->source_id,
            'valid_from' => $record->valid_from?->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'status' => $record->status,
            'status_label' => trans_message("safety_management.requirement_statuses.{$record->status}"),
            'employee' => $this->whenLoaded('employee', fn () => $record->employee ? [
                'id' => $record->employee->id,
                'name' => trim(implode(' ', array_filter([
                    $record->employee->last_name,
                    $record->employee->first_name,
                    $record->employee->middle_name,
                ]))),
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $record->user ? [
                'id' => $record->user->id,
                'name' => $record->user->name,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => $record->project ? [
                'id' => $record->project->id,
                'name' => $record->project->name,
            ] : null),
            'work_type' => $this->whenLoaded('workType', fn () => $record->workType ? [
                'id' => $record->workType->id,
                'name' => $record->workType->name,
                'code' => $record->workType->code,
            ] : null),
            'metadata' => $record->metadata,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }
}
