<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyComplianceResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        if (!$result instanceof SafetyComplianceResult) {
            return [];
        }

        return [
            'employee_id' => $result->employeeId,
            'status' => $result->status,
            'status_label' => $result->statusLabel,
            'blocked' => $result->blocked,
            'expires_soon' => $result->expiresSoon,
            'requirements' => array_map(
                static fn (SafetyComplianceRequirementResult $requirement): array => [
                    'code' => $requirement->code,
                    'type' => $requirement->type,
                    'label' => $requirement->label,
                    'status' => $requirement->status,
                    'severity' => $requirement->severity,
                    'source_type' => $requirement->sourceType,
                    'source_id' => $requirement->sourceId,
                    'valid_until' => $requirement->validUntil?->toIso8601String(),
                    'message' => $requirement->message,
                ],
                $result->requirements
            ),
            'blockers' => $result->blockers,
            'warnings' => $result->warnings,
            'checked_at' => $result->checkedAt->toIso8601String(),
        ];
    }
}
