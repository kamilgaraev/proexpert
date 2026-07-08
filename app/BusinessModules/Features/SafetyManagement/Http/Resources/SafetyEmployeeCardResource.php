<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyEmployeeCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $card = is_array($this->resource) ? $this->resource : [];

        return [
            'employee_id' => $card['employee_id'] ?? null,
            'employee_name' => $card['employee_name'] ?? null,
            'personnel_number' => $card['personnel_number'] ?? null,
            'user_id' => $card['user_id'] ?? null,
            'employment_status' => $card['employment_status'] ?? null,
            'employment_status_label' => $card['employment_status_label'] ?? null,
            'department_label' => $card['department_label'] ?? null,
            'position_label' => $card['position_label'] ?? null,
            'project_id' => $card['project_id'] ?? null,
            'project_label' => $card['project_label'] ?? null,
            'status' => $card['status'] ?? null,
            'status_label' => $card['status_label'] ?? null,
            'next_action_label' => $card['next_action_label'] ?? null,
            'blockers' => $card['blockers'] ?? [],
            'warnings' => $card['warnings'] ?? [],
            'record_counts' => $card['record_counts'] ?? [
                'requirements' => 0,
                'training_records' => 0,
                'medical_exams' => 0,
                'ppe_issues' => 0,
            ],
            'requirements' => SafetyEmployeeRequirementResource::collection($card['requirements'] ?? [])->resolve($request),
            'training_records' => SafetyTrainingRecordResource::collection($card['training_records'] ?? [])->resolve($request),
            'medical_exams' => SafetyMedicalExamResource::collection($card['medical_exams'] ?? [])->resolve($request),
            'ppe_issues' => SafetyPpeIssueResource::collection($card['ppe_issues'] ?? [])->resolve($request),
        ];
    }
}
