<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Controllers\Mobile;

use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyIncidentResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyViolationResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyWorkPermitResource;
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
    public function __construct(
        private readonly SafetyManagementService $service,
    ) {
    }

    public function activePermits(Request $request): JsonResponse
    {
        try {
            $permits = $this->service->activePermitsForUser(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->filled('project_id') ? (int) $request->input('project_id') : null
            );

            return MobileResponse::success(SafetyWorkPermitResource::collection(collect($permits)));
        } catch (\Throwable $exception) {
            Log::error('safety_management.mobile.permits.active.error', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('safety_management.errors.index_failed'), 500);
        }
    }

    public function incidents(Request $request): JsonResponse
    {
        try {
            $incidents = $this->service->paginateIncidents(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'status' => $request->input('status'),
                    'reported_by_user_id' => (int) $request->user()?->id,
                ]
            );

            return MobileResponse::success(SafetyIncidentResource::collection($incidents->getCollection()));
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
            $violations = $this->service->paginateViolations(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'status' => $request->input('status'),
                    'assigned_to_user_id' => (int) $request->user()?->id,
                ]
            );

            return MobileResponse::success(SafetyViolationResource::collection($violations->getCollection()));
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
            'occurred_at.required' => trans_message('safety_management.validation.occurred_at_required'),
            'resolution_comment.required' => trans_message('safety_management.validation.resolution_comment_required'),
        ];
    }
}
