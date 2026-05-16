<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Resources;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkforceEmployee */
final class WorkforceEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkforceEmployee $employee */
        $employee = $this->resource;

        return [
            'id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'user_id' => $employee->user_id,
            'personnel_number' => $employee->personnel_number,
            'last_name' => $employee->last_name,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'full_name' => $employee->full_name,
            'employment_status' => $employee->employment_status,
            'status_label' => trans_message("workforce.employee_statuses.{$employee->employment_status}"),
            'hire_date' => $employee->hire_date?->toDateString(),
            'dismissal_date' => $employee->dismissal_date?->toDateString(),
            'external_payroll_ref' => $employee->external_payroll_ref,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'metadata' => $employee->metadata,
            'user' => $this->whenLoaded('user', fn () => $employee->user ? [
                'id' => $employee->user->id,
                'name' => $employee->user->name,
                'email' => $employee->user->email,
            ] : null),
            'created_at' => $employee->created_at?->toIso8601String(),
            'updated_at' => $employee->updated_at?->toIso8601String(),
        ];
    }
}
