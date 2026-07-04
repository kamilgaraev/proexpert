<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyTrainingRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyTrainingRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        if (!$record instanceof SafetyTrainingRecord) {
            return [];
        }

        return [
            'id' => $record->id,
            'organization_id' => $record->organization_id,
            'employee_id' => $record->employee_id,
            'user_id' => $record->user_id,
            'program_code' => $record->program_code,
            'program_name' => $record->program_name,
            'training_type' => $record->training_type,
            'completed_at' => $record->completed_at?->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'result' => $record->result,
            'result_label' => trans_message("safety_management.training_results.{$record->result}"),
            'document_number' => $record->document_number,
            'protocol_number' => $record->protocol_number,
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
            'metadata' => $record->metadata,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }
}
