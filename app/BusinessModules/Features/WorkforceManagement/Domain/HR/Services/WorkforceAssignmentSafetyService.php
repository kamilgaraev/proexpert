<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkforceAssignmentSafetyService
{
    public function __construct(private readonly HrAccess $access, private readonly SafetyComplianceService $safety) {}

    public function assertPositionPolicy(int $organizationId, array $payload, ?User $actor): void
    {
        if (! array_key_exists('safety_work_category', $payload) && ! array_key_exists('safety_work_type_id', $payload)) {
            return;
        }
        $this->access->assert($organizationId, $actor, 'workforce.settings.manage');
        $data = Validator::make($payload, ['safety_work_category' => ['sometimes', 'nullable', 'string', 'max:80'], 'safety_work_type_id' => ['sometimes', 'nullable', 'integer', 'min:1']])->validate();
        if (isset($data['safety_work_category']) && trim($data['safety_work_category']) === '') {
            $this->fail('assignment_safety_policy_invalid');
        }
        if (isset($data['safety_work_type_id'])) {
            if (empty($data['safety_work_category']) || ! $this->workType($organizationId, (int) $data['safety_work_type_id'])) {
                $this->fail('assignment_safety_policy_invalid');
            }
        }
    }

    public function assertAssignment(int $organizationId, array $payload, ?User $actor): void
    {
        $this->access->assert($organizationId, $actor, 'workforce.structure.manage');
        $data = Validator::make($payload, ['employee_id' => ['required', 'integer', 'min:1'], 'position_id' => ['required', 'integer', 'min:1'], 'project_id' => ['nullable', 'integer', 'min:1'], 'valid_from' => ['required', 'date_format:Y-m-d']])->validate();
        $employeeId = (int) $data['employee_id'];
        if (! WorkforceEmployee::query()->where('organization_id', $organizationId)->whereKey($employeeId)->exists()) {
            $this->fail('assignment_safety_not_admitted');
        }
        $this->assertEligible($organizationId, $employeeId, (int) $data['position_id'], isset($data['project_id']) ? (int) $data['project_id'] : null, $data['valid_from']);
    }

    /** The caller holds the organization mutation lock; no policy means applicability has not been declared. */
    private function assertEligible(int $organizationId, int $employeeId, int $positionId, ?int $projectId, string $effectiveDate): void
    {
        $position = DB::table('workforce_positions')->where('organization_id', $organizationId)->where('id', $positionId)->whereNull('deleted_at')->first();
        if ($position === null) {
            $this->fail('assignment_safety_policy_invalid');
        }
        if ($position->safety_work_category === null && $position->safety_work_type_id === null) {
            return;
        }
        if (empty($position->safety_work_category) || ($position->safety_work_type_id !== null && ! $this->workType($organizationId, (int) $position->safety_work_type_id))) {
            $this->fail('assignment_safety_policy_invalid');
        }
        Validator::make(['date' => $effectiveDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $employee = WorkforceEmployee::query()->where('organization_id', $organizationId)->find($employeeId);
        if ($employee === null || ($projectId !== null && ! Project::query()->where('organization_id', $organizationId)->whereKey($projectId)->exists())) {
            $this->fail('assignment_safety_not_admitted');
        }
        try {
            $result = $this->safety->check(new SafetyComplianceContext(
                organizationId: $organizationId,
                employeeId: $employeeId,
                userId: $employee->user_id,
                projectId: $projectId,
                workTypeId: $position->safety_work_type_id === null ? null : (int) $position->safety_work_type_id,
                workCategory: $position->safety_work_category,
                date: CarbonImmutable::parse($effectiveDate),
                positionName: $position->name,
            ));
        } catch (DomainException|QueryException) {
            // An opted-in assignment cannot proceed if Safety cannot bind its employee or evidence.
            $this->fail('assignment_safety_not_admitted');
        }
        if ($result->blocked) {
            $this->fail('assignment_safety_not_admitted');
        }
    }

    private function workType(int $organizationId, int $workTypeId): bool
    {
        return WorkType::query()->where('organization_id', $organizationId)->whereKey($workTypeId)->where('is_active', true)->exists();
    }

    private function fail(string $key): never
    {
        throw new DomainException(trans_message('workforce.errors.'.$key));
    }
}
