<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyPpeIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyPpeIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        if (!$record instanceof SafetyPpeIssue) {
            return [];
        }

        return [
            'id' => $record->id,
            'organization_id' => $record->organization_id,
            'employee_id' => $record->employee_id,
            'ppe_code' => $record->ppe_code,
            'ppe_name' => $record->ppe_name,
            'issued_at' => $record->issued_at?->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'quantity' => $record->quantity,
            'status' => $record->status,
            'status_label' => trans_message("safety_management.ppe_issue_statuses.{$record->status}"),
            'warehouse_operation_id' => $record->warehouse_operation_id,
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
