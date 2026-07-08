<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Controllers;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyBriefingResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyComplianceResultResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyCorrectiveActionResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyEmployeeCardResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyEmployeeRequirementResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyIncidentResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyInspectionFindingResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyInspectionResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyInspectionTemplateResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyMedicalExamResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyPpeIssueResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyRequirementMatrixResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyTrainingRecordResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyViolationResource;
use App\BusinessModules\Features\SafetyManagement\Http\Resources\SafetyWorkPermitResource;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyDocumentDraftService;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Carbon\CarbonImmutable;
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
        private readonly SafetyComplianceService $complianceService,
        private readonly SafetyDocumentDraftService $documentDraftService,
    ) {
    }

    public function checkAdmission(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'work_type_id' => ['nullable', 'integer'],
                'position_name' => ['nullable', 'string', 'max:255'],
                'work_category' => ['required', 'string', 'max:80'],
                'work_date' => ['nullable', 'date'],
                'permit_id' => ['nullable', 'integer'],
                'work_order_line_id' => ['nullable', 'integer'],
            ], [
                'employee_id.required' => trans_message('safety_management.validation.employee_required'),
                'work_category.required' => trans_message('safety_management.validation.work_category_required'),
            ]);

            $result = $this->complianceService->check(new SafetyComplianceContext(
                organizationId: (int) $request->attributes->get('current_organization_id'),
                employeeId: (int) $validated['employee_id'],
                userId: $request->user()?->id === null ? null : (int) $request->user()->id,
                projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                workTypeId: isset($validated['work_type_id']) ? (int) $validated['work_type_id'] : null,
                workCategory: (string) $validated['work_category'],
                date: isset($validated['work_date']) ? CarbonImmutable::parse((string) $validated['work_date']) : CarbonImmutable::today(),
                positionName: $validated['position_name'] ?? null,
                permitId: isset($validated['permit_id']) ? (int) $validated['permit_id'] : null,
                workOrderLineId: isset($validated['work_order_line_id']) ? (int) $validated['work_order_line_id'] : null,
            ));

            return AdminResponse::success(new SafetyComplianceResultResource($result));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('safety_management.admission.check.error', [
                'user_id' => $request->user()?->id,
                'employee_id' => $request->input('employee_id'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('safety_management.errors.admission_check_failed'), 500);
        }
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

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer'],
            ]);

            return AdminResponse::success($this->service->dashboard(
                (int) $request->attributes->get('current_organization_id'),
                $validated
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'dashboard');
        }
    }

    public function employeeCards(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'employee_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            ], $this->recordValidationMessages());

            $result = $this->service->employeeCards(
                (int) $request->attributes->get('current_organization_id'),
                $validated
            );

            return AdminResponse::success([
                'cards' => SafetyEmployeeCardResource::collection($result['cards'])->resolve($request),
                'summary' => $result['summary'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'employee_cards');
        }
    }

    public function requirementMatrices(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateRequirementMatrices(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'work_type_id', 'work_category', 'risk_level', 'is_active'])
            ), SafetyRequirementMatrixResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'requirement_matrices');
        }
    }

    public function storeRequirementMatrix(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->requirementMatrixRules(), $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyRequirementMatrixResource($this->service->createRequirementMatrix(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.requirement_matrix_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'requirement_matrices');
        }
    }

    public function updateRequirementMatrix(Request $request, int $id): JsonResponse
    {
        try {
            $matrix = $this->service->findRequirementMatrix((int) $request->attributes->get('current_organization_id'), $id);

            if ($matrix === null) {
                return AdminResponse::error(trans_message('safety_management.errors.requirement_matrix_not_found'), 404);
            }

            $validated = $request->validate($this->requirementMatrixRules(required: false), $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyRequirementMatrixResource($this->service->updateRequirementMatrix($matrix, $validated)),
                trans_message('safety_management.messages.record_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function destroyRequirementMatrix(Request $request, int $id): JsonResponse
    {
        try {
            $matrix = $this->service->findRequirementMatrix((int) $request->attributes->get('current_organization_id'), $id);

            if ($matrix === null) {
                return AdminResponse::error(trans_message('safety_management.errors.requirement_matrix_not_found'), 404);
            }

            $this->service->deleteRequirementMatrix($matrix);

            return AdminResponse::success(null, trans_message('safety_management.messages.record_deleted'));
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function inspectionTemplates(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateInspectionTemplates(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['inspection_type', 'is_active'])
            ), SafetyInspectionTemplateResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'inspection_templates');
        }
    }

    public function storeInspectionTemplate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'inspection_type' => ['required', 'string', 'max:80'],
                'checklist_items' => ['required', 'array', 'min:1'],
                'checklist_items.*.code' => ['nullable', 'string', 'max:120'],
                'checklist_items.*.title' => ['required', 'string', 'max:255'],
                'checklist_items.*.requirement_text' => ['nullable', 'string', 'max:5000'],
                'checklist_items.*.severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'is_active' => ['nullable', 'boolean'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyInspectionTemplateResource($this->service->createInspectionTemplate(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.inspection_template_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'inspection_templates');
        }
    }

    public function inspections(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateInspections(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status', 'inspection_type'])
            ), SafetyInspectionResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'inspections');
        }
    }

    public function storeInspection(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'template_id' => ['nullable', 'integer'],
                'permit_id' => ['nullable', 'integer'],
                'conducted_by_user_id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'inspection_type' => ['nullable', 'string', 'max:80'],
                'location_name' => ['nullable', 'string', 'max:255'],
                'risk_level' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
                'status' => ['nullable', 'string', Rule::in(['planned', 'in_progress'])],
                'planned_at' => ['nullable', 'date'],
                'conducted_at' => ['nullable', 'date'],
                'items' => ['nullable', 'array'],
                'items.*.code' => ['nullable', 'string', 'max:120'],
                'items.*.title' => ['required_with:items', 'string', 'max:255'],
                'items.*.requirement_text' => ['nullable', 'string', 'max:5000'],
                'items.*.severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyInspectionResource($this->service->createInspection(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.inspection_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'inspections');
        }
    }

    public function completeInspection(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'conducted_at' => ['nullable', 'date'],
                'result' => ['nullable', 'string', Rule::in(['passed', 'failed', 'passed_with_findings'])],
                'summary' => ['nullable', 'string', 'max:5000'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.id' => ['nullable', 'integer'],
                'items.*.item_code' => ['nullable', 'string', 'max:120'],
                'items.*.status' => ['required', 'string', Rule::in(['compliant', 'non_compliant', 'not_applicable', 'not_checked'])],
                'items.*.comment' => ['nullable', 'string', 'max:5000'],
                'items.*.evidence_files' => ['nullable', 'array'],
                'items.*.assigned_to_user_id' => ['nullable', 'integer'],
                'items.*.due_date' => ['nullable', 'date'],
                'items.*.finding_title' => ['nullable', 'string', 'max:255'],
                'items.*.finding_description' => ['nullable', 'string', 'max:5000'],
                'items.*.metadata' => ['nullable', 'array'],
            ]);

            $inspection = $this->service->findInspection((int) $request->attributes->get('current_organization_id'), $id);

            if ($inspection === null) {
                return AdminResponse::error(trans_message('safety_management.errors.inspection_not_found'), 404);
            }

            return AdminResponse::success(new SafetyInspectionResource($this->service->completeInspection(
                $inspection,
                (int) $request->user()?->id,
                $validated
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function inspectionFindings(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateInspectionFindings(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status', 'assigned_to_user_id'])
            ), SafetyInspectionFindingResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'inspection_findings');
        }
    }

    public function storeInspectionFinding(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'inspection_id' => ['nullable', 'integer'],
                'inspection_item_id' => ['nullable', 'integer'],
                'assigned_to_user_id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'due_date' => ['nullable', 'date'],
                'evidence_files' => ['nullable', 'array'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new SafetyInspectionFindingResource($this->service->createInspectionFinding(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('safety_management.messages.inspection_finding_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'inspection_findings');
        }
    }

    public function resolveInspectionFinding(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['resolution_comment' => ['required', 'string', 'max:1000']]);
            $finding = $this->service->findInspectionFinding((int) $request->attributes->get('current_organization_id'), $id);

            if ($finding === null) {
                return AdminResponse::error(trans_message('safety_management.errors.finding_not_found'), 404);
            }

            return AdminResponse::success(new SafetyInspectionFindingResource($this->service->resolveInspectionFinding(
                $finding,
                (int) $request->user()?->id,
                $validated['resolution_comment']
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
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

    public function employeeRequirements(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateEmployeeRequirements(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['employee_id', 'project_id', 'work_category', 'status'])
            ), SafetyEmployeeRequirementResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'employee_requirements');
        }
    }

    public function storeEmployeeRequirement(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'work_type_id' => ['nullable', 'integer'],
                'work_category' => ['required', 'string', 'max:120'],
                'requirement_code' => ['required', 'string', 'max:120'],
                'requirement_type' => ['required', 'string', 'max:80'],
                'source_type' => ['nullable', 'string', 'max:80'],
                'source_id' => ['nullable', 'integer'],
                'valid_from' => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date'],
                'status' => ['nullable', 'string', Rule::in(['valid', 'expired', 'revoked', 'waived'])],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyEmployeeRequirementResource($this->service->createEmployeeRequirement(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.employee_requirement_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'employee_requirements');
        }
    }

    public function updateEmployeeRequirement(Request $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->findEmployeeRequirement((int) $request->attributes->get('current_organization_id'), $id);

            if ($record === null) {
                return AdminResponse::error(trans_message('safety_management.errors.employee_requirement_not_found'), 404);
            }

            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'work_type_id' => ['nullable', 'integer'],
                'work_category' => ['sometimes', 'string', 'max:120'],
                'requirement_code' => ['sometimes', 'string', 'max:120'],
                'requirement_type' => ['sometimes', 'string', 'max:80'],
                'source_type' => ['nullable', 'string', 'max:80'],
                'source_id' => ['nullable', 'integer'],
                'valid_from' => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date'],
                'status' => ['nullable', 'string', Rule::in(['valid', 'expired', 'revoked', 'waived'])],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyEmployeeRequirementResource($this->service->updateEmployeeRequirement($record, $validated)),
                trans_message('safety_management.messages.record_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function destroyEmployeeRequirement(Request $request, int $id): JsonResponse
    {
        $record = $this->service->findEmployeeRequirement((int) $request->attributes->get('current_organization_id'), $id);

        if ($record === null) {
            return AdminResponse::error(trans_message('safety_management.errors.employee_requirement_not_found'), 404);
        }

        $this->service->deleteEmployeeRequirement($record);

        return AdminResponse::success(null, trans_message('safety_management.messages.record_deleted'));
    }

    public function trainingRecords(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateTrainingRecords(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['employee_id', 'program_code', 'result'])
            ), SafetyTrainingRecordResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'training_records');
        }
    }

    public function storeTrainingRecord(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'program_code' => ['required', 'string', 'max:120'],
                'program_name' => ['required', 'string', 'max:255'],
                'training_type' => ['required', 'string', 'max:80'],
                'completed_at' => ['required', 'date'],
                'valid_until' => ['nullable', 'date'],
                'result' => ['nullable', 'string', Rule::in(['passed', 'failed', 'pending'])],
                'document_number' => ['nullable', 'string', 'max:120'],
                'protocol_number' => ['nullable', 'string', 'max:120'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyTrainingRecordResource($this->service->createTrainingRecord(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.training_record_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'training_records');
        }
    }

    public function updateTrainingRecord(Request $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->findTrainingRecord((int) $request->attributes->get('current_organization_id'), $id);

            if ($record === null) {
                return AdminResponse::error(trans_message('safety_management.errors.training_record_not_found'), 404);
            }

            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer'],
                'user_id' => ['nullable', 'integer'],
                'program_code' => ['sometimes', 'string', 'max:120'],
                'program_name' => ['sometimes', 'string', 'max:255'],
                'training_type' => ['sometimes', 'string', 'max:80'],
                'completed_at' => ['sometimes', 'date'],
                'valid_until' => ['nullable', 'date'],
                'result' => ['nullable', 'string', Rule::in(['passed', 'failed', 'pending'])],
                'document_number' => ['nullable', 'string', 'max:120'],
                'protocol_number' => ['nullable', 'string', 'max:120'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyTrainingRecordResource($this->service->updateTrainingRecord($record, $validated)),
                trans_message('safety_management.messages.record_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function destroyTrainingRecord(Request $request, int $id): JsonResponse
    {
        $record = $this->service->findTrainingRecord((int) $request->attributes->get('current_organization_id'), $id);

        if ($record === null) {
            return AdminResponse::error(trans_message('safety_management.errors.training_record_not_found'), 404);
        }

        $this->service->deleteTrainingRecord($record);

        return AdminResponse::success(null, trans_message('safety_management.messages.record_deleted'));
    }

    public function medicalExams(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginateMedicalExams(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['employee_id', 'exam_type', 'result'])
            ), SafetyMedicalExamResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'medical_exams');
        }
    }

    public function storeMedicalExam(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'exam_type' => ['required', 'string', 'max:80'],
                'completed_at' => ['required', 'date'],
                'valid_until' => ['nullable', 'date'],
                'result' => ['nullable', 'string', Rule::in(['fit', 'fit_with_restrictions', 'not_fit'])],
                'restrictions' => ['nullable', 'string', 'max:5000'],
                'file_id' => ['nullable', 'integer'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyMedicalExamResource($this->service->createMedicalExam(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.medical_exam_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'medical_exams');
        }
    }

    public function updateMedicalExam(Request $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->findMedicalExam((int) $request->attributes->get('current_organization_id'), $id);

            if ($record === null) {
                return AdminResponse::error(trans_message('safety_management.errors.medical_exam_not_found'), 404);
            }

            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer'],
                'exam_type' => ['sometimes', 'string', 'max:80'],
                'completed_at' => ['sometimes', 'date'],
                'valid_until' => ['nullable', 'date'],
                'result' => ['nullable', 'string', Rule::in(['fit', 'fit_with_restrictions', 'not_fit'])],
                'restrictions' => ['nullable', 'string', 'max:5000'],
                'file_id' => ['nullable', 'integer'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyMedicalExamResource($this->service->updateMedicalExam($record, $validated)),
                trans_message('safety_management.messages.record_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function destroyMedicalExam(Request $request, int $id): JsonResponse
    {
        $record = $this->service->findMedicalExam((int) $request->attributes->get('current_organization_id'), $id);

        if ($record === null) {
            return AdminResponse::error(trans_message('safety_management.errors.medical_exam_not_found'), 404);
        }

        $this->service->deleteMedicalExam($record);

        return AdminResponse::success(null, trans_message('safety_management.messages.record_deleted'));
    }

    public function ppeIssues(Request $request): JsonResponse
    {
        try {
            return $this->paginatedResponse($this->service->paginatePpeIssues(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['employee_id', 'ppe_code', 'status'])
            ), SafetyPpeIssueResource::class);
        } catch (\Throwable $exception) {
            return $this->failedIndex($request, $exception, 'ppe_issues');
        }
    }

    public function storePpeIssue(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'ppe_code' => ['required', 'string', 'max:120'],
                'ppe_name' => ['required', 'string', 'max:255'],
                'issued_at' => ['required', 'date'],
                'valid_until' => ['nullable', 'date'],
                'quantity' => ['nullable', 'numeric', 'min:0.001'],
                'status' => ['nullable', 'string', Rule::in(['issued', 'returned', 'lost', 'damaged', 'expired'])],
                'warehouse_operation_id' => ['nullable', 'integer'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyPpeIssueResource($this->service->createPpeIssue(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('safety_management.messages.ppe_issue_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedStore($request, $exception, 'ppe_issues');
        }
    }

    public function updatePpeIssue(Request $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->findPpeIssue((int) $request->attributes->get('current_organization_id'), $id);

            if ($record === null) {
                return AdminResponse::error(trans_message('safety_management.errors.ppe_issue_not_found'), 404);
            }

            $validated = $request->validate([
                'employee_id' => ['sometimes', 'integer'],
                'ppe_code' => ['sometimes', 'string', 'max:120'],
                'ppe_name' => ['sometimes', 'string', 'max:255'],
                'issued_at' => ['sometimes', 'date'],
                'valid_until' => ['nullable', 'date'],
                'quantity' => ['nullable', 'numeric', 'min:0.001'],
                'status' => ['nullable', 'string', Rule::in(['issued', 'returned', 'lost', 'damaged', 'expired'])],
                'warehouse_operation_id' => ['nullable', 'integer'],
                'metadata' => ['nullable', 'array'],
            ], $this->recordValidationMessages());

            return AdminResponse::success(
                new SafetyPpeIssueResource($this->service->updatePpeIssue($record, $validated)),
                trans_message('safety_management.messages.record_updated')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function destroyPpeIssue(Request $request, int $id): JsonResponse
    {
        $record = $this->service->findPpeIssue((int) $request->attributes->get('current_organization_id'), $id);

        if ($record === null) {
            return AdminResponse::error(trans_message('safety_management.errors.ppe_issue_not_found'), 404);
        }

        $this->service->deletePpeIssue($record);

        return AdminResponse::success(null, trans_message('safety_management.messages.record_deleted'));
    }

    public function draftBriefingJournal(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer'],
                'briefing_type' => ['nullable', 'string', 'max:80'],
                'date_from' => ['nullable', 'date'],
                'date_until' => ['nullable', 'date'],
            ]);

            return AdminResponse::success($this->documentDraftService->briefingJournal(
                (int) $request->attributes->get('current_organization_id'),
                $validated
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function draftPpeCard(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
            ], [
                'employee_id.required' => trans_message('safety_management.validation.employee_required'),
            ]);

            return AdminResponse::success($this->documentDraftService->ppeCard(
                (int) $request->attributes->get('current_organization_id'),
                (int) $validated['employee_id']
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
        }
    }

    public function draftViolationAct(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'violation_id' => ['nullable', 'integer'],
                'finding_id' => ['nullable', 'integer'],
            ]);

            return AdminResponse::success($this->documentDraftService->violationAct(
                (int) $request->attributes->get('current_organization_id'),
                $validated
            ));
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failedAction($request, $exception);
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
                'participants' => ['nullable', 'array'],
                'participants.*.employee_id' => ['nullable', 'integer'],
                'participants.*.user_id' => ['nullable', 'integer'],
                'participants.*.external_name' => ['nullable', 'string', 'max:255'],
                'participants.*.company_name' => ['nullable', 'string', 'max:255'],
                'participants.*.role_name' => ['nullable', 'string', 'max:255'],
                'participants.*.position_name' => ['nullable', 'string', 'max:255'],
                'participants.*.work_category' => ['nullable', 'string', 'max:80'],
                'participants.*.metadata' => ['nullable', 'array'],
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

    public function syncPermitParticipants(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'participants' => ['required', 'array'],
                'participants.*.employee_id' => ['nullable', 'integer'],
                'participants.*.user_id' => ['nullable', 'integer'],
                'participants.*.external_name' => ['nullable', 'string', 'max:255'],
                'participants.*.company_name' => ['nullable', 'string', 'max:255'],
                'participants.*.role_name' => ['nullable', 'string', 'max:255'],
                'participants.*.position_name' => ['nullable', 'string', 'max:255'],
                'participants.*.work_category' => ['nullable', 'string', 'max:80'],
                'participants.*.metadata' => ['nullable', 'array'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->permitAction(
            $request,
            $id,
            fn ($permit) => $this->service->syncPermitParticipants($permit, $validated['participants'])
        );
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
            ], [
                'project_id.required' => trans_message('safety_management.validation.project_required'),
                'title.required' => trans_message('safety_management.validation.title_required'),
                'incident_type.required' => trans_message('safety_management.validation.incident_type_required'),
                'incident_type.in' => trans_message('safety_management.validation.incident_type_invalid'),
                'severity.required' => trans_message('safety_management.validation.severity_required'),
                'severity.in' => trans_message('safety_management.validation.severity_invalid'),
                'occurred_at.required' => trans_message('safety_management.validation.occurred_at_required'),
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
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'high', 'critical'])],
                'location_name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'assigned_to_user_id' => ['nullable', 'integer'],
                'due_date' => ['nullable', 'date'],
                'corrective_action' => ['nullable', 'string', 'max:5000'],
                'metadata' => ['nullable', 'array'],
            ], [
                'project_id.required' => trans_message('safety_management.validation.project_required'),
                'title.required' => trans_message('safety_management.validation.title_required'),
                'severity.required' => trans_message('safety_management.validation.severity_required'),
                'severity.in' => trans_message('safety_management.validation.severity_invalid'),
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
                'signature_deadline_at' => ['nullable', 'date', 'after_or_equal:conducted_at'],
                'topics' => ['nullable', 'array'],
                'topics.*' => ['string', 'max:160'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'participants' => ['required', 'array', 'min:1'],
                'participants.*.employee_id' => ['nullable', 'integer'],
                'participants.*.user_id' => ['nullable', 'integer'],
                'participants.*.external_name' => ['nullable', 'string', 'max:255'],
                'participants.*.company_name' => ['nullable', 'string', 'max:255'],
                'participants.*.role_name' => ['nullable', 'string', 'max:255'],
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

    public function addBriefingParticipants(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'participants' => ['required', 'array', 'min:1'],
                'participants.*.employee_id' => ['nullable', 'integer'],
                'participants.*.user_id' => ['nullable', 'integer'],
                'participants.*.external_name' => ['nullable', 'string', 'max:255'],
                'participants.*.company_name' => ['nullable', 'string', 'max:255'],
                'participants.*.role_name' => ['nullable', 'string', 'max:255'],
                'participants.*.metadata' => ['nullable', 'array'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->addBriefingParticipants($briefing, $validated['participants'])
        );
    }

    public function signBriefingParticipant(Request $request, int $id, int $participantId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'signature_method' => ['nullable', 'string', 'max:40'],
                'metadata' => ['nullable', 'array'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->signBriefingParticipant(
                $briefing,
                $participantId,
                (int) $request->user()?->id,
                (string) ($validated['signature_method'] ?? 'admin'),
                $validated['metadata'] ?? []
            ),
            trans_message('safety_management.messages.briefing_signed')
        );
    }

    public function markBriefingParticipantAbsent(Request $request, int $id, int $participantId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'absence_reason' => ['required', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->markBriefingParticipantAbsent($briefing, $participantId, $validated['absence_reason']),
            trans_message('safety_management.messages.briefing_participant_absent')
        );
    }

    public function markBriefingParticipantRefused(Request $request, int $id, int $participantId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'refusal_reason' => ['required', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->markBriefingParticipantRefused($briefing, $participantId, $validated['refusal_reason']),
            trans_message('safety_management.messages.briefing_participant_refused')
        );
    }

    public function completeBriefing(Request $request, int $id): JsonResponse
    {
        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->completeBriefing($briefing, (int) $request->user()?->id),
            trans_message('safety_management.messages.briefing_completed')
        );
    }

    public function cancelBriefing(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cancellation_reason' => ['required', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->briefingAction(
            $request,
            $id,
            fn ($briefing) => $this->service->cancelBriefing($briefing, (int) $request->user()?->id, $validated['cancellation_reason']),
            trans_message('safety_management.messages.briefing_cancelled')
        );
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

    private function briefingAction(Request $request, int $id, callable $action, ?string $message = null): JsonResponse
    {
        try {
            $briefing = $this->service->findBriefing((int) $request->attributes->get('current_organization_id'), $id);

            if ($briefing === null) {
                return AdminResponse::error(trans_message('safety_management.errors.briefing_not_found'), 404);
            }

            return AdminResponse::success(new SafetyBriefingResource($action($briefing)), $message);
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

    private function requirementMatrixRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'project_id' => ['nullable', 'integer'],
            'work_type_id' => ['nullable', 'integer'],
            'position_name' => ['nullable', 'string', 'max:255'],
            'work_category' => [$presence, 'string', 'max:80'],
            'risk_level' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'requirements' => [$presence, 'array', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    private function recordValidationMessages(): array
    {
        return [
            'employee_id.required' => trans_message('safety_management.validation.employee_required'),
            'work_category.required' => trans_message('safety_management.validation.work_category_required'),
            'requirements.required' => trans_message('safety_management.validation.requirements_required'),
            'requirements.min' => trans_message('safety_management.validation.requirements_required'),
            'requirement_code.required' => trans_message('safety_management.validation.requirement_code_required'),
            'requirement_type.required' => trans_message('safety_management.validation.requirement_type_required'),
            'program_code.required' => trans_message('safety_management.validation.program_code_required'),
            'program_name.required' => trans_message('safety_management.validation.program_name_required'),
            'training_type.required' => trans_message('safety_management.validation.training_type_required'),
            'completed_at.required' => trans_message('safety_management.validation.completed_at_required'),
            'exam_type.required' => trans_message('safety_management.validation.exam_type_required'),
            'ppe_code.required' => trans_message('safety_management.validation.ppe_code_required'),
            'ppe_name.required' => trans_message('safety_management.validation.ppe_name_required'),
            'issued_at.required' => trans_message('safety_management.validation.issued_at_required'),
            'status.in' => trans_message('safety_management.validation.status_invalid'),
            'result.in' => trans_message('safety_management.validation.status_invalid'),
            'per_page.integer' => trans_message('safety_management.validation.per_page_invalid'),
            'per_page.min' => trans_message('safety_management.validation.per_page_invalid'),
            'per_page.max' => trans_message('safety_management.validation.per_page_invalid'),
        ];
    }
}
