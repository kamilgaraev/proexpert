<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ErpControls\ErpControlAuditIndexRequest;
use App\Http\Requests\Api\V1\Admin\ErpControls\ErpControlCheckRequest;
use App\Http\Requests\Api\V1\Admin\ErpControls\ErpControlConflictIndexRequest;
use App\Http\Requests\Api\V1\Admin\ErpControls\ErpControlConflictResolveRequest;
use App\Http\Requests\Api\V1\Admin\ErpControls\ErpControlPolicyIndexRequest;
use App\Http\Resources\Api\V1\Admin\ErpControls\ErpControlAuditEventResource;
use App\Http\Resources\Api\V1\Admin\ErpControls\ErpControlConflictResource;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\ErpControls\ErpControlAuditQueryService;
use App\Services\ErpControls\ErpControlDecisionService;
use App\Services\ErpControls\ErpControlRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class ErpControlsController extends Controller
{
    public function __construct(
        private readonly ErpControlRegistry $registry,
        private readonly ErpControlDecisionService $decisionService,
        private readonly ErpControlAuditQueryService $auditQueryService,
        private readonly AuthorizationService $authorizationService,
    ) {
    }

    public function policies(ErpControlPolicyIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $policies = $this->registry->policies($filters);

            return AdminResponse::success(
                $policies,
                trans_message('erp_controls.messages.policies_loaded'),
                200,
                $this->registry->summary($policies)
            );
        } catch (Throwable $e) {
            Log::error('erp_controls.policies.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('erp_controls.messages.policies_load_error'), 500);
        }
    }

    public function check(ErpControlCheckRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $scope = $validated['scope'] ?? [];
            $scope['organization_id'] = $scope['organization_id'] ?? $this->organizationId($request);

            $decision = $this->decisionService->check(
                organizationId: $this->organizationId($request),
                actorUserId: $request->user()?->id,
                operationCode: (string) $validated['operation'],
                entityType: $validated['entity_type'] ?? null,
                entityId: $validated['entity_id'] ?? null,
                scope: $scope,
                reason: $validated['reason'] ?? null,
            );

            return AdminResponse::success(
                $decision->toArray(),
                trans_message('erp_controls.messages.check_completed')
            );
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }

            Log::error('erp_controls.check_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('erp_controls.messages.check_error'), 500);
        }
    }

    public function conflicts(ErpControlConflictIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $conflicts = $this->auditQueryService->conflicts($this->organizationId($request), $filters);

            return AdminResponse::paginated(
                ErpControlConflictResource::collection($conflicts->getCollection()),
                [
                    'current_page' => $conflicts->currentPage(),
                    'per_page' => $conflicts->perPage(),
                    'total' => $conflicts->total(),
                    'last_page' => $conflicts->lastPage(),
                ],
                trans_message('erp_controls.messages.conflicts_loaded'),
                200,
                $this->auditQueryService->conflictsSummary($this->organizationId($request), $filters)
            );
        } catch (Throwable $e) {
            Log::error('erp_controls.conflicts.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('erp_controls.messages.conflicts_load_error'), 500);
        }
    }

    public function resolveConflict(ErpControlConflictResolveRequest $request, string $conflict): JsonResponse
    {
        try {
            $validated = $request->validated();
            $event = $this->auditQueryService->findConflict($this->organizationId($request), $conflict);

            if ($event === null) {
                return AdminResponse::error(trans_message('erp_controls.messages.conflict_not_found'), 404);
            }

            $this->assertResolutionAllowed($request, (string) $event->severity, $validated);

            $resolution = $this->auditQueryService->resolve(
                conflict: $event,
                actorUserId: (int) $request->user()?->id,
                decision: (string) $validated['decision'],
                reason: (string) $validated['reason'],
                secondApproverUserId: isset($validated['second_approver_user_id'])
                    ? (int) $validated['second_approver_user_id']
                    : null
            );

            return AdminResponse::success(
                new ErpControlAuditEventResource($resolution),
                trans_message('erp_controls.messages.conflict_resolved')
            );
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }

            Log::error('erp_controls.conflicts.resolve_error', [
                'user_id' => $request->user()?->id,
                'conflict_id' => $conflict,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('erp_controls.messages.conflict_resolve_error'), 500);
        }
    }

    public function audit(ErpControlAuditIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $events = $this->auditQueryService->audit($this->organizationId($request), $filters);

            return AdminResponse::paginated(
                ErpControlAuditEventResource::collection($events->getCollection()),
                [
                    'current_page' => $events->currentPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                    'last_page' => $events->lastPage(),
                ],
                trans_message('erp_controls.messages.audit_loaded'),
                200,
                $this->auditQueryService->auditSummary($this->organizationId($request), $filters)
            );
        } catch (Throwable $e) {
            Log::error('erp_controls.audit.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('erp_controls.messages.audit_load_error'), 500);
        }
    }

    private function assertResolutionAllowed(Request $request, string $riskLevel, array $validated): void
    {
        if (($validated['decision'] ?? null) !== 'accepted_risk' || $riskLevel !== 'critical') {
            return;
        }

        $secondApproverId = isset($validated['second_approver_user_id'])
            ? (int) $validated['second_approver_user_id']
            : null;

        if ($secondApproverId === null || $secondApproverId === (int) $request->user()?->id) {
            throw ValidationException::withMessages([
                'second_approver_user_id' => [trans_message('erp_controls.messages.second_approver_required')],
            ]);
        }

        $secondApprover = User::query()->find($secondApproverId);

        if (! $secondApprover instanceof User || ! $this->authorizationService->can($secondApprover, 'erp_controls.override.approve', [
            'organization_id' => $this->organizationId($request),
        ])) {
            throw ValidationException::withMessages([
                'second_approver_user_id' => [trans_message('erp_controls.messages.second_approver_not_allowed')],
            ]);
        }
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return AdminResponse::error(
            trans_message('erp_controls.messages.validation_error'),
            422,
            $exception->errors()
        );
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
