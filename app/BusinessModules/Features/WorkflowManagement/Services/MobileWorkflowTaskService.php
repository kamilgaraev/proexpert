<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkflowManagement\Services;

use App\BusinessModules\Features\WorkflowManagement\DTOs\MobileWorkflowTaskPage;
use App\Models\CompletedWork;
use App\Services\Contract\ContractAuditedMutationService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileWorkflowTaskService
{
    public function __construct(private readonly ContractAuditedMutationService $contractMutations) {}

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
        int $organizationId,
        array $filters,
        int $perPage
    ): MobileWorkflowTaskPage {
        $query = $this->baseQuery($organizationId, $filters);
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

    public function findTask(int $organizationId, int $taskId): CompletedWork
    {
        $task = $this->withRelations(CompletedWork::query())
            ->where('organization_id', $organizationId)
            ->whereKey($taskId)
            ->first();

        if (! $task) {
            throw new DomainException(trans_message('workflow_management.errors.task_not_found'));
        }

        return $task;
    }

    public function approve(CompletedWork $task, int $userId, ?string $comment): CompletedWork
    {
        return $this->transition($task, $userId, 'approve', $comment);
    }

    public function reject(CompletedWork $task, int $userId, string $reason): CompletedWork
    {
        return $this->transition($task, $userId, 'reject', $reason);
    }

    public function requestChanges(CompletedWork $task, int $userId, string $comment): CompletedWork
    {
        return $this->transition($task, $userId, 'request_changes', $comment);
    }

    public function addComment(CompletedWork $task, int $userId, string $comment): CompletedWork
    {
        return DB::transaction(function () use ($task, $userId, $comment): CompletedWork {
            $workflow = $this->workflowData($task);
            $workflow['comments'][] = $this->entry(
                action: 'comment',
                userId: $userId,
                fromStatus: $task->status,
                toStatus: $task->status,
                comment: $comment
            );

            $task->forceFill([
                'additional_info' => $this->withWorkflowData($task, $workflow),
            ])->save();

            return $this->findTask((int) $task->organization_id, (int) $task->id);
        });
    }

    private function transition(
        CompletedWork $task,
        int $userId,
        string $action,
        ?string $comment
    ): CompletedWork {
        $transition = self::TRANSITIONS[$action];

        if (! in_array($task->status, $transition['from'], true)) {
            throw new DomainException(trans_message('workflow_management.errors.status_transition_forbidden'));
        }

        return DB::transaction(function () use ($task, $userId, $action, $comment, $transition): CompletedWork {
            $fromStatus = (string) $task->status;
            $toStatus = (string) $transition['to'];
            $workflow = $this->workflowData($task);
            $workflow['status_history'][] = $this->entry($action, $userId, $fromStatus, $toStatus, $comment);

            if ($comment !== null && trim($comment) !== '') {
                $workflow['comments'][] = $this->entry('comment', $userId, $fromStatus, $toStatus, $comment);
            }

            $task->forceFill([
                'status' => $toStatus,
                'additional_info' => $this->withWorkflowData($task, $workflow),
            ])->save();

            if ($toStatus === 'confirmed') {
                if ($task->contract !== null) {
                    $this->contractMutations->syncCompletionStatus($task->contract, $userId, [
                        'completed_work_id' => (int) $task->id,
                    ]);
                }
            }

            return $this->findTask((int) $task->organization_id, (int) $task->id);
        });
    }

    private function baseQuery(int $organizationId, array $filters): Builder
    {
        return $this->withRelations(CompletedWork::query())
            ->where('organization_id', $organizationId)
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
