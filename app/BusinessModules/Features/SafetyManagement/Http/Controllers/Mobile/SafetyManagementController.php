<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Controllers\Mobile;

use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyIncidentResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyViolationResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyWorkPermitResource;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SafetyManagementController extends Controller
{
    private const PERMIT_STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'active',
        'suspended',
        'rejected',
        'closed',
        'cancelled',
    ];

    private const INCIDENT_STATUSES = [
        'reported',
        'triage',
        'investigation',
        'corrective_actions',
        'closed',
        'cancelled',
    ];

    private const VIOLATION_STATUSES = [
        'open',
        'resolved',
        'closed',
    ];

    public function __construct(
        private readonly SafetyManagementService $service,
    ) {
    }

    public function permits(Request $request): JsonResponse
    {
        try {
            $filters = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(self::PERMIT_STATUSES)],
            ]);

            $permits = $this->service->mobilePermitsForUser(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $filters
            );

            return MobileResponse::success(SafetyWorkPermitResource::collection(collect($permits)));
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.permits.index.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.index_failed'), 500);
        }
    }

    public function showPermit(Request $request, int $id): JsonResponse
    {
        try {
            $permit = $this->mobilePermit($request, $id);

            if ($permit === null) {
                return MobileResponse::error(trans_message('safety_management.errors.permit_not_found'), 404);
            }

            return MobileResponse::success(new SafetyWorkPermitResource($permit));
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.permits.show.error', [
                'user_id' => $request->user()?->id,
                'permit_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.index_failed'), 500);
        }
    }

    public function incidents(Request $request): JsonResponse
    {
        try {
            $filters = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(self::INCIDENT_STATUSES)],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $incidents = $this->service->paginateIncidents(
                (int) $request->attributes->get('current_organization_id'),
                (int) ($filters['per_page'] ?? 20),
                [
                    'project_id' => $filters['project_id'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'reported_by_user_id' => (int) $request->user()?->id,
                ]
            );

            return MobileResponse::success(SafetyIncidentResource::collection($incidents->getCollection()));
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.incidents.index.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.index_failed'), 500);
        }
    }

    public function violations(Request $request): JsonResponse
    {
        try {
            $filters = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(self::VIOLATION_STATUSES)],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $violations = $this->service->paginateViolations(
                (int) $request->attributes->get('current_organization_id'),
                (int) ($filters['per_page'] ?? 20),
                [
                    'project_id' => $filters['project_id'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'assigned_to_user_id' => (int) $request->user()?->id,
                ]
            );

            return MobileResponse::success(SafetyViolationResource::collection($violations->getCollection()));
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.violations.index.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.index_failed'), 500);
        }
    }

    public function storeIncident(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'incident_type' => ['required', 'string', Rule::in([
                    'unsafe_condition',
                    'near_miss',
                    'injury',
                    'property_damage',
                    'environmental',
                    'other',
                ])],
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'occurred_at' => ['required', 'date'],
                'location_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'immediate_actions' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return MobileResponse::success(
                new SafetyIncidentResource($this->service->createIncident(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.incident_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.incidents.store.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.store_failed'), 500);
        }
    }

    public function storeViolation(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'location_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'due_date' => ['nullable', 'date'],
                'corrective_action' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return MobileResponse::success(
                new SafetyViolationResource($this->service->createViolation(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.violation_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.violations.store.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.store_failed'), 500);
        }
    }

    public function resolveViolation(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['resolution_comment' => ['required', 'string', 'max:1000']]);
            $violation = $this->service->findViolation((int) $request->attributes->get('current_organization_id'), $id);

            if ($violation === null) {
                return MobileResponse::error(trans_message('safety_management.errors.violation_not_found'), 404);
            }

            return MobileResponse::success(new SafetyViolationResource($this->service->resolveViolation(
                $violation,
                (int) $request->user()?->id,
                $validated['resolution_comment']
            )));
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.violations.resolve.error', [
                'user_id' => $request->user()?->id,
                'violation_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.action_failed'), 500);
        }
    }

    public function submitPermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->submitPermit($permit),
            'submit'
        );
    }

    public function approvePermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['approval_comment' => ['nullable', 'string', 'max:1000']]);

            return $this->permitAction(
                $request,
                $id,
                fn ($permit) => $this->service->approvePermit(
                    $permit,
                    (int) $request->user()?->id,
                    $validated['approval_comment'] ?? null
                ),
                'approve'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    public function rejectPermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

            return $this->permitAction(
                $request,
                $id,
                fn ($permit) => $this->service->rejectPermit($permit, (int) $request->user()?->id, $validated['reason']),
                'reject'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    public function activatePermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->activatePermit($permit),
            'activate'
        );
    }

    public function suspendPermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

            return $this->permitAction(
                $request,
                $id,
                fn ($permit) => $this->service->suspendPermit($permit, (int) $request->user()?->id, $validated['reason']),
                'suspend'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    public function resumePermit(Request $request, int $id): JsonResponse
    {
        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->resumePermit($permit),
            'resume'
        );
    }

    public function closePermit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['close_comment' => ['required', 'string', 'max:1000']]);

            return $this->permitAction(
                $request,
                $id,
                fn ($permit) => $this->service->closePermit($permit, (int) $request->user()?->id, $validated['close_comment']),
                'close'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('safety_management.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    private function permitAction(Request $request, int $id, callable $action, string $logAction): JsonResponse
    {
        try {
            $permit = $this->mobilePermit($request, $id);

            if ($permit === null) {
                return MobileResponse::error(trans_message('safety_management.errors.permit_not_found'), 404);
            }

            return MobileResponse::success(new SafetyWorkPermitResource($action($permit)));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.permits.action.error', [
                'action' => $logAction,
                'user_id' => $request->user()?->id,
                'permit_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.action_failed'), 500);
        }
    }

    private function mobilePermit(Request $request, int $id): ?SafetyWorkPermit
    {
        return $this->service->findMobilePermit(
            (int) $request->attributes->get('current_organization_id'),
            (int) $request->user()?->id,
            $id
        );
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationMessages(): array
    {
        return [
            'project_id.required' => trans_message('safety_management.validation.project_required'),
            'title.required' => trans_message('safety_management.validation.title_required'),
            'incident_type.required' => trans_message('safety_management.validation.incident_type_required'),
            'incident_type.in' => trans_message('safety_management.validation.incident_type_invalid'),
            'severity.required' => trans_message('safety_management.validation.severity_required'),
            'severity.in' => trans_message('safety_management.validation.severity_invalid'),
            'project_id.integer' => trans_message('safety_management.validation.project_invalid'),
            'status.in' => trans_message('safety_management.validation.status_invalid'),
            'per_page.integer' => trans_message('safety_management.validation.per_page_invalid'),
            'per_page.min' => trans_message('safety_management.validation.per_page_invalid'),
            'per_page.max' => trans_message('safety_management.validation.per_page_invalid'),
            'occurred_at.required' => trans_message('safety_management.validation.occurred_at_required'),
            'resolution_comment.required' => trans_message('safety_management.validation.resolution_comment_required'),
            'reason.required' => trans_message('safety_management.validation.reason_required'),
            'reason.max' => trans_message('safety_management.validation.reason_too_long'),
            'close_comment.required' => trans_message('safety_management.validation.close_comment_required'),
            'close_comment.max' => trans_message('safety_management.validation.comment_too_long'),
            'approval_comment.max' => trans_message('safety_management.validation.comment_too_long'),
        ];
    }
}
