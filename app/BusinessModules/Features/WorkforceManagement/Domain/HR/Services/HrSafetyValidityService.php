<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

final class HrSafetyValidityService
{
    public function __construct(private readonly HrAccess $access, private readonly SafetyComplianceService $safety) {}

    /** Occupational safety owns medical/training evidence; HR receives applicability and expiry only. */
    public function forEmployee(int $organizationId, int $employeeId, array $filters, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor);
        $employee = WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
        $projectId = isset($filters['project_id']) ? (int) $filters['project_id'] : null;
        if ($projectId !== null) {
            Project::query()->where('organization_id', $organizationId)->findOrFail($projectId);
        }
        $result = $this->safety->check(new SafetyComplianceContext(organizationId: $organizationId, employeeId: $employeeId,
            userId: $employee->user_id, projectId: $projectId, workCategory: $filters['work_category'] ?? 'general',
            date: CarbonImmutable::parse($filters['work_date'] ?? now()->toDateString())));
        return ['employee_id' => $employeeId, 'checked_at' => $result->checkedAt->toIso8601String(),
            'requirements' => array_values(array_map(static fn (SafetyComplianceRequirementResult $requirement): array => [
                'code' => $requirement->code, 'type' => $requirement->type, 'applicable' => true,
                'valid_until' => $requirement->validUntil?->toDateString(),
                'validity' => match ($requirement->status) { 'fulfilled', 'restricted' => 'valid', 'expired' => 'expired', default => 'requires_review' },
                'source_type' => $requirement->sourceType, 'source_id' => $requirement->sourceId,
            ], array_filter($result->requirements, static fn (SafetyComplianceRequirementResult $requirement): bool => in_array($requirement->type, ['medical_exam', 'training', 'qualification'], true))))];
    }
}
