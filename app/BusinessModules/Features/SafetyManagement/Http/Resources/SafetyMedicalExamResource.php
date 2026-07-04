<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyMedicalExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        if (!$record instanceof SafetyMedicalExam) {
            return [];
        }

        return [
            'id' => $record->id,
            'organization_id' => $record->organization_id,
            'employee_id' => $record->employee_id,
            'exam_type' => $record->exam_type,
            'completed_at' => $record->completed_at?->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'result' => $record->result,
            'result_label' => trans_message("safety_management.medical_exam_results.{$record->result}"),
            'restrictions' => $record->restrictions,
            'file_id' => $record->file_id,
            'employee' => $this->whenLoaded('employee', fn () => $record->employee ? [
                'id' => $record->employee->id,
                'name' => trim(implode(' ', array_filter([
                    $record->employee->last_name,
                    $record->employee->first_name,
                    $record->employee->middle_name,
                ]))),
            ] : null),
            'metadata' => $record->metadata,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }
}
