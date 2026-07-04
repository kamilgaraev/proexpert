<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyRequirementMatrixResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $matrix = $this->resource;

        if (!$matrix instanceof SafetyRequirementMatrix) {
            return [];
        }

        return [
            'id' => $matrix->id,
            'organization_id' => $matrix->organization_id,
            'project_id' => $matrix->project_id,
            'work_type_id' => $matrix->work_type_id,
            'position_name' => $matrix->position_name,
            'work_category' => $matrix->work_category,
            'risk_level' => $matrix->risk_level,
            'requirements' => $matrix->requirements ?? [],
            'is_active' => $matrix->is_active,
            'effective_from' => $matrix->effective_from?->toDateString(),
            'effective_until' => $matrix->effective_until?->toDateString(),
            'project' => $this->whenLoaded('project', fn () => $matrix->project ? [
                'id' => $matrix->project->id,
                'name' => $matrix->project->name,
            ] : null),
            'work_type' => $this->whenLoaded('workType', fn () => $matrix->workType ? [
                'id' => $matrix->workType->id,
                'name' => $matrix->workType->name,
                'code' => $matrix->workType->code,
            ] : null),
            'created_at' => $matrix->created_at?->toIso8601String(),
            'updated_at' => $matrix->updated_at?->toIso8601String(),
        ];
    }
}
