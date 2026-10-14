<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\EmploymentRelationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrLifecycleService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrSafetyValidityService;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\EmploymentRelationRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrCandidateRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrCandidateStageRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrChecklistRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrChecklistCompletionRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrDevelopmentRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrVacancyRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrSafetyValidityRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceHrController extends Controller
{
    public function __construct(private readonly EmploymentRelationService $relations, private readonly HrLifecycleService $lifecycle, private readonly HrSafetyValidityService $safety) {}

    public function history(Request $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->relations->history($this->organization($request), $employeeId, $request->user()));
    }

    public function recordRelation(EmploymentRelationRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->relations->record($this->organization($request), $employeeId, $request->validated(), $request->user()), 201);
    }

    public function validity(HrSafetyValidityRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->safety->forEmployee($this->organization($request), $employeeId, $request->validated(), $request->user()));
    }

    public function recruiting(Request $request): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->recruiting($this->organization($request), $request->user()));
    }

    public function storeVacancy(HrVacancyRequest $request): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->vacancy($this->organization($request), $request->validated(), $request->user()), 201);
    }

    public function closeVacancy(Request $request, int $vacancyId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->closeVacancy($this->organization($request), $vacancyId, $request->user()));
    }

    public function storeCandidate(HrCandidateRequest $request): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->candidate($this->organization($request), $request->validated(), $request->user()), 201);
    }

    public function moveCandidate(HrCandidateStageRequest $request, int $candidateId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->moveCandidate($this->organization($request), $candidateId, $request->validated(), $request->user()));
    }

    public function lifecycle(Request $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->employeeLifecycle($this->organization($request), $employeeId, $request->user()));
    }

    public function storeChecklist(HrChecklistRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->checklist($this->organization($request), $employeeId, $request->validated(), $request->user()), 201);
    }

    public function completeChecklist(HrChecklistCompletionRequest $request, int $employeeId, int $itemId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->completeChecklist($this->organization($request), $employeeId, $itemId, $request->validated('completion_reference'), $request->user()));
    }

    public function storeDevelopment(HrDevelopmentRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->lifecycle->development($this->organization($request), $employeeId, $request->validated(), $request->user()), 201);
    }

    private function organization(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function response(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return AdminResponse::success($operation(), null, $status);
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
