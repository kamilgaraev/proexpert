<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeTrackingService;
use DomainException;

final class MobileTimeEntryApprovalService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly TimeTrackingService $timeTracking,
    ) {}

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function pending(User $actor, int $organizationId, array $filters): array
    {
        $projectIds = $this->projectAccess->ids($actor, $organizationId);
        $projectIds = array_values(array_filter(
            $projectIds,
            fn (int $projectId): bool => $this->can($actor, 'time_tracking.view', $organizationId, $projectId),
        ));
        if (isset($filters['project_id'])) {
            $projectId = (int) $filters['project_id'];
            $this->assertProject($actor, $organizationId, $projectId);
            $projectIds = in_array($projectId, $projectIds, true) ? [$projectId] : [];
        }

        $paginator = TimeEntry::query()
            ->with(['project:id,name', 'user:id,name', 'workType:id,name', 'task:id,name', 'approvedBy:id,name'])
            ->where('organization_id', $organizationId)
            ->where('status', 'submitted')
            ->whereIn('project_id', $projectIds)
            ->orderBy('work_date')
            ->orderBy('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => collect($paginator->items())->map(fn (TimeEntry $entry): array => $this->present($entry, $actor, $organizationId))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function decide(User $actor, int $organizationId, int $entryId, string $action, ?string $reason = null): array
    {
        $entry = TimeEntry::query()
            ->where('organization_id', $organizationId)
            ->whereKey($entryId)
            ->first();
        if ($entry === null) {
            throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404);
        }

        $projectId = (int) $entry->project_id;
        $this->assertProject($actor, $organizationId, $projectId);
        $permission = $action === 'approve' ? 'time_tracking.approve' : 'time_tracking.reject';
        $this->assertPermission($actor, $permission, $organizationId, $projectId);
        if (! $entry->canBeApproved()) {
            throw new BusinessLogicException(trans_message('time_tracking.already_approved'), 409);
        }

        $success = $action === 'approve'
            ? $this->timeTracking->approveTimeEntry($entryId, $actor)
            : $this->timeTracking->rejectTimeEntry($entryId, $actor, (string) $reason);
        if (! $success) {
            throw new BusinessLogicException(trans_message('time_tracking.approve_failed'), 409);
        }

        $entry->refresh()->load(['project:id,name', 'user:id,name', 'workType:id,name', 'task:id,name', 'approvedBy:id,name']);

        return $this->present($entry, $actor, $organizationId);
    }

    /** @return array<string, mixed> */
    private function present(TimeEntry $entry, User $actor, int $organizationId): array
    {
        $projectId = (int) $entry->project_id;
        $actions = [];
        if ($entry->canBeApproved()) {
            if ($this->can($actor, 'time_tracking.approve', $organizationId, $projectId)) {
                $actions[] = 'approve';
            }
            if ($this->can($actor, 'time_tracking.reject', $organizationId, $projectId)) {
                $actions[] = 'reject';
            }
        }

        return [
            'id' => (int) $entry->id,
            'organization_id' => (int) $entry->organization_id,
            'user_id' => (int) $entry->user_id,
            'worker_label' => (string) ($entry->user?->name ?? $entry->worker_name ?? ''),
            'project_id' => $projectId,
            'project_label' => (string) ($entry->project?->name ?? ''),
            'work_type_id' => $entry->work_type_id,
            'work_type_label' => $entry->workType?->name,
            'task_id' => $entry->task_id,
            'task_label' => $entry->task?->name,
            'work_date' => $entry->work_date?->format('Y-m-d'),
            'start_time' => $entry->start_time?->format('H:i'),
            'end_time' => $entry->end_time?->format('H:i'),
            'hours_worked' => (float) $entry->hours_worked,
            'break_time' => (float) $entry->break_time,
            'title' => (string) $entry->title,
            'description' => $entry->description,
            'status' => (string) $entry->status,
            'status_label' => (string) $entry->status_label,
            'is_active_timer' => false,
            'is_billable' => (bool) $entry->is_billable,
            'location' => $entry->location,
            'notes' => $entry->notes,
            'approved_by_user_id' => $entry->approved_by_user_id,
            'approved_by_label' => $entry->approvedBy?->name,
            'approved_at' => $entry->approved_at?->toIso8601String(),
            'rejection_reason' => $entry->rejection_reason,
            'corrections' => [],
            'available_actions' => $actions,
            'approval_summary' => [
                'status' => (string) $entry->status,
                'status_label' => (string) $entry->status_label,
                'approved_by_label' => $entry->approvedBy?->name,
                'approved_at' => $entry->approved_at?->toIso8601String(),
                'rejection_reason' => $entry->rejection_reason,
            ],
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    private function assertPermission(User $actor, string $permission, int $organizationId, int $projectId): void
    {
        if (! $this->can($actor, $permission, $organizationId, $projectId)) {
            throw new BusinessLogicException(trans_message('errors.forbidden'), 403);
        }
    }

    private function assertProject(User $actor, int $organizationId, int $projectId): void
    {
        try {
            $this->projectAccess->assert($actor, $organizationId, $projectId, trans_message('errors.resource_not_found'));
        } catch (DomainException $exception) {
            throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404, $exception);
        }
    }

    private function can(User $actor, string $permission, int $organizationId, int $projectId): bool
    {
        return $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ]);
    }
}
