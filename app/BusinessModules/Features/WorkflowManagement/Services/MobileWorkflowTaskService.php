<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkflowManagement\Services;

use App\BusinessModules\Features\WorkflowManagement\DTOs\MobileWorkflowTaskPage;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkFactReadiness;
use App\Services\CompletedWork\CompletedWorkScopeResolver;
use App\Services\CompletedWork\CompletedWorkWorkflowService;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileWorkflowTaskService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
        private readonly CompletedWorkScopeResolver $scopeResolver,
        private readonly CompletedWorkWorkflowService $workWorkflow,
        private readonly CompletedWorkFactReadiness $factReadiness,
    ) {}

    public const STATUSES = [
        'draft',
        'pending',
        'in_review',
        'confirmed',
        'cancelled',
        'rejected',
    ];

    private const TRANSITIONS = [
        'approve' => [
            'from' => ['draft', 'pending', 'in_review'],
            'to' => 'confirmed',
        ],
        'reject' => [
            'from' => ['draft', 'pending', 'in_review'],
            'to' => 'rejected',
        ],
        'request_changes' => [
            'from' => ['draft', 'pending'],
            'to' => 'in_review',
        ],
    ];

    public function paginateTasks(
        User $actor,
        int $organizationId,
        array $filters,
        int $perPage
    ): MobileWorkflowTaskPage {
        $this->assertOrganization($actor, $organizationId, 'completed_works.view');
        $query = $this->baseQuery($actor, $organizationId, $filters);
        $summary = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($value) => (int) $value)
            ->all();

        $paginator = $query
            ->orderByDesc('completion_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return new MobileWorkflowTaskPage($paginator, $summary);
    }

    public function findTask(User $actor, int $organizationId, int $taskId): CompletedWork
    {
        $this->assertOrganization($actor, $organizationId, 'completed_works.view');
        $task = $this->baseQuery($actor, $organizationId, [])
            ->whereKey($taskId)
            ->first();

        if (! $task) {
            throw new DomainException(trans_message('workflow_management.errors.task_not_found'));
        }

        return $task;
    }

    public function approve(CompletedWork $task, User $actor, ?string $comment): CompletedWork
    {
        return $this->transition($task, $actor, 'approve', $comment);
    }

    public function reject(CompletedWork $task, User $actor, string $reason): CompletedWork
    {
        return $this->transition($task, $actor, 'reject', $reason);
    }

    public function requestChanges(CompletedWork $task, User $actor, string $comment): CompletedWork
    {
        return $this->transition($task, $actor, 'request_changes', $comment);
    }

    public function addComment(CompletedWork $task, User $actor, string $comment): CompletedWork
    {
        return DB::transaction(function () use ($task, $actor, $comment): CompletedWork {
            $task = $this->lockMutableTask($task, $actor);
            if ($task->status === CompletedWork::STATUS_CANCELLED) {
                throw new DomainException(trans_message('workflow_management.errors.status_transition_forbidden'));
            }
            $workflow = $this->workflowData($task);
            $workflow['comments'][] = $this->entry(
                action: 'comment',
                userId: (int) $actor->id,
                fromStatus: $task->status,
                toStatus: $task->status,
                comment: $comment
            );

            $task->forceFill([
                'additional_info' => $this->withWorkflowData($task, $workflow),
            ])->save();

            return $this->findTask($actor, (int) $task->organization_id, (int) $task->id);
        });
    }

    private function transition(
        CompletedWork $task,
        User $actor,
        string $action,
        ?string $comment
    ): CompletedWork {
        $transition = self::TRANSITIONS[$action];

        return DB::transaction(function () use ($task, $actor, $action, $comment, $transition): CompletedWork {
            $task = $this->lockMutableTask($task, $actor);
            if (! in_array($task->status, $transition['from'], true)) {
                throw new DomainException(trans_message('workflow_management.errors.status_transition_forbidden'));
            }
            $fromStatus = (string) $task->status;
            $toStatus = (string) $transition['to'];
            if ($action === 'approve') {
                $task = $this->workWorkflow->confirm($task, $actor);
            } elseif ($action === 'request_changes') {
                $this->factReadiness->assertReady($task);
            }
            $workflow = $this->workflowData($task);
            $workflow['status_history'][] = $this->entry($action, (int) $actor->id, $fromStatus, $toStatus, $comment);

            if ($comment !== null && trim($comment) !== '') {
                $workflow['comments'][] = $this->entry('comment', (int) $actor->id, $fromStatus, $toStatus, $comment);
            }

            $task->forceFill([
                'status' => $toStatus,
                'additional_info' => $this->withWorkflowData($task, $workflow),
            ])->save();

            return $this->findTask($actor, (int) $task->organization_id, (int) $task->id);
        });
    }

    public function availableActions(User $actor, CompletedWork $task): array
    {
        if ($task->work_origin_type === CompletedWork::ORIGIN_JOURNAL
            || (int) $actor->current_organization_id !== (int) $task->organization_id
            || ! $this->authorization->can($actor, 'completed_works.edit', [
                'organization_id' => (int) $task->organization_id,
                'project_id' => (int) $task->project_id,
                'strict_project_scope' => true,
            ])) {
            return [];
        }

        $actions = match ($task->status) {
            'draft', 'pending' => ['approve', 'reject', 'request_changes'],
            'in_review' => ['approve', 'reject'],
            default => [],
        };
        try {
            $this->factReadiness->assertReady($task);
        } catch (BusinessLogicException) {
            $actions = array_values(array_diff($actions, ['approve', 'request_changes']));
        }
        if ($task->status !== CompletedWork::STATUS_CANCELLED) {
            $actions[] = 'comment';
        }

        return $actions;
    }

    private function lockMutableTask(CompletedWork $task, User $actor): CompletedWork
    {
        $this->assertOrganization($actor, (int) $task->organization_id, 'completed_works.edit');
        $lockedTask = $this->withRelations(CompletedWork::query())->whereKey($task->id)->lockForUpdate()->first();
        if (! $lockedTask) {
            throw new DomainException(trans_message('workflow_management.errors.task_not_found'));
        }
        $this->assertOrganization($actor, (int) $lockedTask->organization_id, 'completed_works.edit');
        if ($lockedTask->work_origin_type === CompletedWork::ORIGIN_JOURNAL) {
            throw new BusinessLogicException(trans_message('workflow_management.errors.journal_approval_required'), 422);
        }
        $this->scopeResolver->assertCorrection($lockedTask, $actor);

        return $lockedTask;
    }

    private function assertOrganization(User $actor, int $organizationId, string $permission): void
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId)
            || ! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('workflow_management.errors.permission_denied'), 403);
        }
    }

    private function baseQuery(User $actor, int $organizationId, array $filters): Builder
    {
        return $this->withRelations(CompletedWork::query())
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query): void {
                $query->whereNull('work_origin_type')->orWhere('work_origin_type', '!=', CompletedWork::ORIGIN_JOURNAL);
            })
            ->whereHas('project', static fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->whereIn('project_id', $this->projectAccess->queryAccessibleProjects($actor, $organizationId)->select('projects.id'))
            ->when(isset($filters['project_id']), static function (Builder $query) use ($filters): void {
                $query->where('project_id', (int) $filters['project_id']);
            })
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            })
            ->when(isset($filters['assigned_to_user_id']), static function (Builder $query) use ($filters): void {
                $query->where('user_id', (int) $filters['assigned_to_user_id']);
            })
            ->when(isset($filters['search']), static function (Builder $query) use ($filters): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
                $query->where(static function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('notes', 'like', $search)
                        ->orWhereHas('project', static fn (Builder $projectQuery) => $projectQuery->where('name', 'like', $search))
                        ->orWhereHas('workType', static fn (Builder $workTypeQuery) => $workTypeQuery->where('name', 'like', $search))
                        ->orWhereHas('contract', static fn (Builder $contractQuery) => $contractQuery->where('number', 'like', $search));
                });
            });
    }

    private function withRelations(Builder $query): Builder
    {
        return $query->with([
            'project',
            'workType.measurementUnit',
            'user',
            'contract.contractor',
            'contractor',
            'scheduleTask.schedule',
            'scheduleTask.measurementUnit',
            'estimateItem.measurementUnit',
        ]);
    }

    private function workflowData(CompletedWork $task): array
    {
        $additionalInfo = is_array($task->additional_info) ? $task->additional_info : [];
        $workflow = is_array($additionalInfo['mobile_workflow'] ?? null)
            ? $additionalInfo['mobile_workflow']
            : [];

        return [
            'status_history' => array_values(is_array($workflow['status_history'] ?? null) ? $workflow['status_history'] : []),
            'comments' => array_values(is_array($workflow['comments'] ?? null) ? $workflow['comments'] : []),
        ];
    }

    private function withWorkflowData(CompletedWork $task, array $workflow): array
    {
        $additionalInfo = is_array($task->additional_info) ? $task->additional_info : [];
        $additionalInfo['mobile_workflow'] = [
            'status_history' => array_values($workflow['status_history'] ?? []),
            'comments' => array_values($workflow['comments'] ?? []),
        ];

        return $additionalInfo;
    }

    private function entry(
        string $action,
        int $userId,
        string $fromStatus,
        string $toStatus,
        ?string $comment
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'user_id' => $userId,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
