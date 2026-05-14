<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Controllers;

use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyBriefingResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyCorrectiveActionResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyIncidentResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyViolationResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyWorkPermitResource;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SafetyManagementController extends Controller
{
    public function __construct(
        private readonly SafetyManagementService $service,
    ) {
    }

    public function permits(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginatePermits(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), SafetyWorkPermitResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'permits');
        }
    }

    public function incidents(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateIncidents(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), SafetyIncidentResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'incidents');
        }
    }

    public function violations(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateViolations(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), SafetyViolationResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'violations');
        }
    }

    public function briefings(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateBriefings(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id'])
            ), SafetyBriefingResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'briefings');
        }
    }

    public function correctiveActions(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateCorrectiveActions(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status', 'incident_id', 'violation_id'])
            ), SafetyCorrectiveActionResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'corrective_actions');
        }
    }

    public function storePermit(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'permit_type' => ['required', 'string', 'max:80'],
                'location_name' => ['nullable', 'string', 'max:255'],
                'valid_from' => ['required', 'date'],
                'valid_until' => ['required', 'date', 'after:valid_from'],
                'responsible_user_id' => ['nullable', 'integer'],
                'risk_level' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
                'required_controls' => ['nullable', 'array'],
                'required_controls.*' => ['string', 'max:120'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyWorkPermitResource($this->service->createPermit(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.permit_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'permits');
        }
    }

    public function submitPermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction($request, $id, fn ($permit) => $this->service->submitPermit($permit));
    }

    public function approvePermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->approvePermit($permit, (int) $request->user()?->id, $validated['comment'] ?? null)
        );
    }

    public function rejectPermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->rejectPermit($permit, (int) $request->user()?->id, $validated['reason'])
        );
    }

    public function activatePermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction($request, $id, fn ($permit) => $this->service->activatePermit($permit));
    }

    public function suspendPermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->suspendPermit($permit, (int) $request->user()?->id, $validated['reason'])
        );
    }

    public function resumePermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction($request, $id, fn ($permit) => $this->service->resumePermit($permit));
    }

    public function closePermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['close_comment' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->closePermit($permit, (int) $request->user()?->id, $validated['close_comment'])
        );
    }

    public function storeIncident(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'incident_type' => ['required', 'string', 'max:80'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'occurred_at' => ['required', 'date'],
                'location_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'immediate_actions' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyIncidentResource($this->service->createIncident(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.incident_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'incidents');
        }
    }

    public function triageIncident(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->incidentAction(
            $request,
            $id,
            fn ($incident) => $this->service->triageIncident($incident, (int) $request->user()?->id, $validated['comment'] ?? null)
        );
    }

    public function startIncidentInvestigation(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['assigned_to_user_id' => ['required', 'integer']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->incidentAction(
            $request,
            $id,
            fn ($incident) => $this->service->startIncidentInvestigation($incident, (int) $validated['assigned_to_user_id'])
        );
    }

    public function startCorrectiveActions(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['root_cause' => ['nullable', 'string', 'max:5000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->incidentAction(
            $request,
            $id,
            fn ($incident) => $this->service->startCorrectiveActions($incident, $validated['root_cause'] ?? null)
        );
    }

    public function cancelIncident(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->incidentAction(
            $request,
            $id,
            fn ($incident) => $this->service->cancelIncident($incident, (int) $request->user()?->id, $validated['reason'])
        );
    }

    public function closeIncident(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'root_cause' => ['nullable', 'string', 'max:5000'],
                'corrective_actions' => ['nullable', 'string', 'max:5000'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->incidentAction(
            $request,
            $id,
            fn ($incident) => $this->service->closeIncident($incident, (int) $request->user()?->id, $validated)
        );
    }

    public function storeViolation(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'location_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'assigned_to_user_id' => ['nullable', 'integer'],
                'due_date' => ['nullable', 'date'],
                'corrective_action' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyViolationResource($this->service->createViolation(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.violation_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'violations');
        }
    }

    public function resolveViolation(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['resolution_comment' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->violationAction(
            $request,
            $id,
            fn ($violation) => $this->service->resolveViolation($violation, (int) $request->user()?->id, $validated['resolution_comment'])
        );
    }

    public function storeBriefing(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'briefing_type' => ['required', 'string', 'max:80'],
                'location_name' => ['nullable', 'string', 'max:255'],
                'conducted_at' => ['required', 'date'],
                'topics' => ['nullable', 'array'],
                'topics.*' => ['string', 'max:160'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'participants' => ['required', 'array', 'min:1'],
                'participants.*.user_id' => ['nullable', 'integer'],
                'participants.*.external_name' => ['nullable', 'string', 'max:255'],
                'participants.*.company_name' => ['nullable', 'string', 'max:255'],
                'participants.*.role_name' => ['nullable', 'string', 'max:255'],
                'participants.*.signed_at' => ['nullable', 'date'],
                'participants.*.metadata' => ['nullable', 'array'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyBriefingResource($this->service->createBriefing(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.briefing_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'briefings');
        }
    }

    public function storeCorrectiveAction(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'incident_id' => ['nullable', 'integer'],
                'violation_id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'assigned_to_user_id' => ['nullable', 'integer'],
                'due_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyCorrectiveActionResource($this->service->createCorrectiveAction(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.corrective_action_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'corrective_actions');
        }
    }

    public function resolveCorrectiveAction(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['resolution_comment' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->correctiveAction(
            $request,
            $id,
            fn ($action) => $this->service->resolveCorrectiveAction($action, (int) $request->user()?->id, $validated['resolution_comment'])
        );
    }

    public function verifyCorrectiveAction(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['verification_comment' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->correctiveAction(
            $request,
            $id,
            fn ($action) => $this->service->verifyCorrectiveAction($action, (int) $request->user()?->id, $validated['verification_comment'])
        );
    }

    private function permitAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            $permit = $this->service->findPermit((int) $request->attributes->get('current_organization_id'), $id);

            if ($permit === null) {
                return AdminResponse::error(trans_message('safety_management.errors.permit_not_found'), 404);
            }

            return AdminResponse::success(new SafetyWorkPermitResource($action($permit)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    private function incidentAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            $incident = $this->service->findIncident((int) $request->attributes->get('current_organization_id'), $id);

            if ($incident === null) {
                return AdminResponse::error(trans_message('safety_management.errors.incident_not_found'), 404);
            }

            return AdminResponse::success(new SafetyIncidentResource($action($incident)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    private function violationAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            $violation = $this->service->findViolation((int) $request->attributes->get('current_organization_id'), $id);

            if ($violation === null) {
                return AdminResponse::error(trans_message('safety_management.errors.violation_not_found'), 404);
            }

            return AdminResponse::success(new SafetyViolationResource($action($violation)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    private function correctiveAction(Request $request, int $id, callable $action): JsonResponse
    {
        try {
            $correctiveAction = $this->service->findCorrectiveAction((int) $request->attributes->get('current_organization_id'), $id);

            if ($correctiveAction === null) {
                return AdminResponse::error(trans_message('safety_management.errors.corrective_action_not_found'), 404);
            }

            return AdminResponse::success(new SafetyCorrectiveActionResource($action($correctiveAction)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    private function paginatedResponse(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return AdminResponse::paginated(
            $resourceClass::collection($paginator->getCollection()),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }

    private function failedIndex(Request $request, \Throwable $exception, string $scope): JsonResponse
    {
        Log::error("safety_management.{$scope}.index.error", [
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('safety_management.errors.index_failed'), 500);
    }

    private function failedStore(Request $request, \Throwable $exception, string $scope): JsonResponse
    {
        Log::error("safety_management.{$scope}.store.error", [
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('safety_management.errors.store_failed'), 500);
    }

    private function failedAction(Request $request, \Throwable $exception): JsonResponse
    {
        Log::error('safety_management.action.error', [
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('safety_management.errors.action_failed'), 500);
    }
}
