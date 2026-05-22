<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Http\Resources\MobileBudgetEstimateResource;
use App\BusinessModules\Features\BudgetEstimates\Services\MobileBudgetEstimateService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BudgetEstimateController extends Controller
{
    public function __construct(
        private readonly MobileBudgetEstimateService $service,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['budget-estimates.view', 'budget-estimates.view_all'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['required', 'integer'],
            ]);
            $user = $request->user();
            if (!$user instanceof User) {
                return MobileResponse::error(
                    trans_message('budget_estimates.mobile.errors.permission_denied'),
                    403,
                    null,
                    ['error_code' => 'PERMISSION_DENIED']
                );
            }

            $summary = $this->service->projectSummary(
                (int) $request->attributes->get('current_organization_id'),
                (int) $validated['project_id'],
                $user
            );

            $summary['estimates'] = MobileBudgetEstimateResource::collection($summary['estimates'])->resolve();
            $summary['assigned_approvals'] = MobileBudgetEstimateResource::collection($summary['assigned_approvals'])->resolve();

            return MobileResponse::success($summary);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'summary');
        }
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['budget-estimates.view', 'budget-estimates.view_all'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['required', 'integer'],
                'status' => ['nullable', 'string', Rule::in(MobileBudgetEstimateService::STATUSES)],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);
            $result = $this->service->paginateEstimates(
                (int) $request->attributes->get('current_organization_id'),
                $validated,
                min((int) $request->input('per_page', 20), 50)
            );
            $paginator = $result->paginator;

            return MobileResponse::success([
                'items' => MobileBudgetEstimateResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'summary' => $result->summary,
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function show(Request $request, int $estimate): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['budget-estimates.view', 'budget-estimates.view_all'])) {
            return $denied;
        }

        try {
            $model = $this->service->findEstimate(
                (int) $request->attributes->get('current_organization_id'),
                $estimate
            );

            return MobileResponse::success([
                'estimate' => (new MobileBudgetEstimateResource($model))->resolve(),
                'linked_change_requests' => $this->service->linkedChangesForEstimate(
                    (int) $request->attributes->get('current_organization_id'),
                    $model
                ),
            ]);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'show');
        }
    }

    public function approve(Request $request, int $estimate): JsonResponse
    {
        return $this->approvalAction($request, $estimate, 'approve');
    }

    public function requestChanges(Request $request, int $estimate): JsonResponse
    {
        return $this->approvalAction($request, $estimate, 'request_changes');
    }

    private function approvalAction(Request $request, int $estimate, string $action): JsonResponse
    {
        if ($denied = $this->ensureAnyPermission($request, ['budget-estimates.approve'])) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, match ($action) {
                'approve' => ['comment' => ['nullable', 'string', 'max:2000']],
                'request_changes' => ['comment' => ['required', 'string', 'max:2000']],
            });
            $model = $this->service->findEstimate(
                (int) $request->attributes->get('current_organization_id'),
                $estimate
            );
            $user = $request->user();
            if (!$user instanceof User) {
                return MobileResponse::error(
                    trans_message('budget_estimates.mobile.errors.permission_denied'),
                    403,
                    null,
                    ['error_code' => 'PERMISSION_DENIED']
                );
            }
            $updated = match ($action) {
                'approve' => $this->service->approve($model, (int) $user->id, $validated['comment'] ?? null),
                'request_changes' => $this->service->requestChanges($model, (int) $user->id, $validated['comment']),
            };

            return MobileResponse::success(
                new MobileBudgetEstimateResource($updated),
                trans_message("budget_estimates.mobile.messages.{$action}")
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $action);
        }
    }

    private function ensureAnyPermission(Request $request, array $permissions): ?JsonResponse
    {
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return MobileResponse::error(
                trans_message('budget_estimates.mobile.errors.permission_denied'),
                403,
                null,
                ['error_code' => 'PERMISSION_DENIED']
            );
        }

        foreach ($permissions as $permission) {
            if ($this->authorizationService->can($user, $permission, ['organization_id' => $organizationId])) {
                return null;
            }
        }

        return MobileResponse::error(
            trans_message('budget_estimates.mobile.errors.permission_denied'),
            403,
            null,
            ['error_code' => 'PERMISSION_DENIED']
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

    private function validationFailed(ValidationException $exception): JsonResponse
    {
        return MobileResponse::error(
            trans_message('budget_estimates.mobile.errors.validation_failed'),
            422,
            $exception->errors()
        );
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('budget_estimates.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('budget_estimates.mobile.errors.action_failed'), 500);
    }

    private function validationMessages(): array
    {
        return [
            'project_id.required' => trans_message('budget_estimates.mobile.validation.project_required'),
            'project_id.integer' => trans_message('budget_estimates.mobile.validation.project_invalid'),
            'status.in' => trans_message('budget_estimates.mobile.validation.status_invalid'),
            'comment.required' => trans_message('budget_estimates.mobile.validation.comment_required'),
            'comment.max' => trans_message('budget_estimates.mobile.validation.comment_too_long'),
            'per_page.max' => trans_message('budget_estimates.mobile.validation.per_page_max'),
        ];
    }
}
