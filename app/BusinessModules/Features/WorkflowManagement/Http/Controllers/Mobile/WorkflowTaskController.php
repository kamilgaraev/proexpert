<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkflowManagement\Http\Controllers\Mobile;

use App\BusinessModules\Features\WorkflowManagement\Http\Resources\MobileWorkflowTaskResource;
use App\BusinessModules\Features\WorkflowManagement\Services\MobileWorkflowTaskService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkflowTaskController extends Controller
{
    public function __construct(
        private readonly MobileWorkflowTaskService $service,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'completed_works.view')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', Rule::in(MobileWorkflowTaskService::STATUSES)],
                'assigned_to_me' => ['nullable', 'boolean'],
                'search' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            if ($request->has('assigned_to_me') && $request->boolean('assigned_to_me')) {
                $validated['assigned_to_user_id'] = (int) $request->user()?->id;
            }

            $result = $this->service->paginateTasks(
                (int) $request->attributes->get('current_organization_id'),
                $validated,
                min((int) $request->input('per_page', 20), 50)
            );

            $paginator = $result->paginator;

            return MobileResponse::success([
                'items' => MobileWorkflowTaskResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'summary' => [
                    'by_status' => $result->summary,
                    'project_id' => $validated['project_id'] ?? null,
                    'status' => $validated['status'] ?? null,
                    'assigned_to_me' => $request->has('assigned_to_me') ? $request->boolean('assigned_to_me') : null,
                    'search' => $validated['search'] ?? null,
                ],
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function show(Request $request, int $task): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'completed_works.view')) {
            return $denied;
        }

        try {
            return MobileResponse::success(new MobileWorkflowTaskResource($this->service->findTask(
                (int) $request->attributes->get('current_organization_id'),
                $task
            )));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'show');
        }
    }

    public function approve(Request $request, int $task): JsonResponse
    {
        return $this->action($request, $task, 'approve');
    }

    public function reject(Request $request, int $task): JsonResponse
    {
        return $this->action($request, $task, 'reject');
    }

    public function requestChanges(Request $request, int $task): JsonResponse
    {
        return $this->action($request, $task, 'request_changes');
    }

    public function comment(Request $request, int $task): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'completed_works.edit')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'comment' => ['required', 'string', 'max:2000'],
            ]);

            $model = $this->service->findTask(
                (int) $request->attributes->get('current_organization_id'),
                $task
            );

            return MobileResponse::success(
                new MobileWorkflowTaskResource($this->service->addComment(
                    $model,
                    (int) $request->user()?->id,
                    $validated['comment']
                )),
                trans_message('workflow_management.messages.comment_added')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'comment');
        }
    }

    private function action(Request $request, int $task, string $action): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'completed_works.edit')) {
            return $denied;
        }

        try {
            $rules = match ($action) {
                'approve' => ['comment' => ['nullable', 'string', 'max:2000']],
                'reject' => ['reason' => ['required', 'string', 'max:2000']],
                'request_changes' => ['comment' => ['required', 'string', 'max:2000']],
            };
            $validated = $this->validated($request, $rules);
            $model = $this->service->findTask(
                (int) $request->attributes->get('current_organization_id'),
                $task
            );

            $updated = match ($action) {
                'approve' => $this->service->approve($model, (int) $request->user()?->id, $validated['comment'] ?? null),
                'reject' => $this->service->reject($model, (int) $request->user()?->id, $validated['reason']),
                'request_changes' => $this->service->requestChanges($model, (int) $request->user()?->id, $validated['comment']),
            };

            return MobileResponse::success(
                new MobileWorkflowTaskResource($updated),
                trans_message("workflow_management.messages.{$action}")
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $action);
        }
    }

    private function ensurePermission(Request $request, string $permission): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$this->authorizationService->can($user, $permission, [
            'organization_id' => (int) $request->attributes->get('current_organization_id'),
        ])) {
            return MobileResponse::error(
                trans_message('workflow_management.errors.permission_denied'),
                403,
                null,
                ['error_code' => 'PERMISSION_DENIED']
            );
        }

        return null;
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
            trans_message('workflow_management.errors.validation_failed'),
            422,
            $exception->errors()
        );
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('workflow_management.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('workflow_management.errors.action_failed'), 500);
    }

    private function validationMessages(): array
    {
        return [
            'status.in' => trans_message('workflow_management.validation.status_invalid'),
            'project_id.integer' => trans_message('workflow_management.validation.project_invalid'),
            'comment.required' => trans_message('workflow_management.validation.comment_required'),
            'comment.max' => trans_message('workflow_management.validation.comment_too_long'),
            'reason.required' => trans_message('workflow_management.validation.reason_required'),
            'reason.max' => trans_message('workflow_management.validation.reason_too_long'),
            'per_page.max' => trans_message('workflow_management.validation.per_page_max'),
        ];
    }
}
