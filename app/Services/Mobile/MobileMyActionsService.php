<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MobileMyActionsService
{
    public function __construct(
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly AuthorizationService $authorization,
    ) {}

    public function paginate(User $actor, int $organizationId, ?int $projectId, int $page, int $perPage): LengthAwarePaginator
    {
        $projectIds = $this->projectAccess->ids($actor, $organizationId);
        if ($projectId !== null) {
            $projectIds = in_array($projectId, $projectIds, true) ? [$projectId] : [];
        }

        $queries = [];
        foreach ($projectIds as $id) {
            $context = ['organization_id' => $organizationId, 'project_id' => $id];
            if ($this->allowed($actor, 'site_requests.view', $context)) {
                $queries[] = DB::table('site_requests as action')
                    ->join('projects as project', 'project.id', '=', 'action.project_id')
                    ->where('project.organization_id', $organizationId)->whereNull('project.deleted_at')
                    ->where('action.organization_id', $organizationId)->where('action.project_id', $id)
                    ->where('action.assigned_to', $actor->id)->whereNull('action.deleted_at')
                    ->whereNotIn('action.status', ['draft', 'completed', 'cancelled', 'rejected'])
                    ->selectRaw("action.id, 'site_request' as type, action.title, action.status, action.project_id, project.name as project_name, action.required_date as due_at");
            }
            if (
                $this->allowed($actor, 'procurement.purchase_requests.view', $context)
                || $this->allowed($actor, 'procurement.view', $context)
            ) {
                $queries[] = DB::table('purchase_requests as action')
                    ->join('site_requests as site_request', 'site_request.id', '=', 'action.site_request_id')
                    ->join('projects as project', 'project.id', '=', 'site_request.project_id')
                    ->where('action.organization_id', $organizationId)->where('action.assigned_to', $actor->id)
                    ->whereNotNull('action.site_request_id')->whereNull('action.deleted_at')
                    ->where('site_request.organization_id', $organizationId)->whereNull('site_request.deleted_at')
                    ->where('site_request.project_id', $id)
                    ->where('project.organization_id', $organizationId)->whereNull('project.deleted_at')
                    ->whereNotIn('action.status', ['draft', 'rejected', 'cancelled'])
                    ->selectRaw("action.id, 'purchase_request' as type, action.request_number as title, action.status, site_request.project_id, project.name as project_name, site_request.required_date as due_at");
            }
            if ($this->allowed($actor, 'schedule.view', $context)) {
                $queries[] = DB::table('schedule_tasks as action')
                    ->join('project_schedules as schedule', 'schedule.id', '=', 'action.schedule_id')
                    ->join('projects as project', 'project.id', '=', 'schedule.project_id')
                    ->where('project.organization_id', $organizationId)->whereNull('project.deleted_at')
                    ->where('action.organization_id', $organizationId)->where('schedule.organization_id', $organizationId)
                    ->where('schedule.project_id', $id)->where('action.assigned_user_id', $actor->id)
                    ->whereNull('action.deleted_at')->whereNull('schedule.deleted_at')
                    ->whereNotIn('action.status', ['completed', 'cancelled'])
                    ->selectRaw("action.id, 'schedule_task' as type, action.name as title, action.status, schedule.project_id, project.name as project_name, action.planned_end_date as due_at");
            }
            if (
                $this->allowed($actor, 'quality-control.view', $context)
                && $this->allowed($actor, 'quality-control.defects.view', $context)
            ) {
                $queries[] = DB::table('quality_defects as action')
                    ->join('projects as project', 'project.id', '=', 'action.project_id')
                    ->where('project.organization_id', $organizationId)->whereNull('project.deleted_at')
                    ->where('action.organization_id', $organizationId)->where('action.project_id', $id)
                    ->where('action.assigned_to', $actor->id)->whereNull('action.deleted_at')
                    ->whereNotIn('action.status', ['draft', 'resolved', 'cancelled'])
                    ->selectRaw("action.id, 'quality_defect' as type, action.title, action.status, action.project_id, project.name as project_name, action.due_date as due_at");
            }
            if ($this->allowed($actor, 'payments.invoice.view', $context)
                || $this->allowed($actor, 'payments.invoice.view_all', $context)) {
                $canClaimPaymentApproval = $this->allowed($actor, 'payments.transaction.approve', $context)
                    || $this->allowed($actor, 'payments.transaction.reject', $context);
                $queries[] = DB::table('payment_approvals as approval')
                    ->join('payment_documents as action', 'action.id', '=', 'approval.payment_document_id')
                    ->join('projects as project', 'project.id', '=', 'action.project_id')
                    ->where('approval.organization_id', $organizationId)
                    ->where(static function (Builder $query) use ($actor, $canClaimPaymentApproval): void {
                        $query->where('approval.approver_user_id', $actor->id);
                        if ($canClaimPaymentApproval) {
                            $query->orWhere(static function (Builder $permissionQuery): void {
                                $permissionQuery->whereNull('approval.approver_user_id')
                                    ->where('approval.approval_permission', 'payments.transaction.approve');
                            });
                        }
                    })
                    ->where('approval.status', 'pending')
                    ->where('action.organization_id', $organizationId)
                    ->where('action.project_id', $id)
                    ->whereNull('action.deleted_at')->whereNull('project.deleted_at')
                    ->whereNotIn('action.status', ['paid', 'cancelled', 'rejected'])
                    ->selectRaw("action.id, 'payment_document' as type, action.document_number as title, action.status, action.project_id, project.name as project_name, action.due_date as due_at")
                    ->distinct();
            }
        }

        if ($queries === []) {
            return new LengthAwarePaginator(collect(), 0, $perPage, $page);
        }

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $rows = DB::query()->fromSub($union, 'actions');
        $total = (clone $rows)->count();
        $items = $rows->orderByRaw('due_at asc nulls last')->orderBy('type')->orderBy('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get()
            ->map(function (object $row) use ($actor, $organizationId): array {
                $projectId = (int) $row->project_id;
                $context = ['organization_id' => $organizationId, 'project_id' => $projectId];
                $actions = match ($row->type) {
                    'site_request' => array_values(array_filter([
                        in_array($row->status, ['draft', 'pending'], true) && $this->allowed($actor, 'site_requests.edit', $context) ? 'edit' : null,
                        $this->allowed($actor, 'site_requests.change_status', $context) ? 'change_status' : null,
                    ])),
                    'schedule_task' => $this->allowed($actor, 'schedule.edit', $context) ? ['update'] : [],
                    'payment_document' => array_values(array_filter([
                        'view',
                        $this->allowed($actor, 'payments.transaction.approve', $context) ? 'approve' : null,
                        $this->allowed($actor, 'payments.transaction.reject', $context) ? 'reject' : null,
                    ])),
                    'purchase_request' => ['view'],
                    default => $this->qualityActions($actor, $context, (string) $row->status),
                };

                return [
                    'id' => (int) $row->id,
                    'type' => $row->type,
                    'route' => $row->type,
                    'title' => (string) $row->title,
                    'status' => (string) $row->status,
                    'project_id' => $projectId,
                    'project_name' => (string) $row->project_name,
                    'due_at' => $row->due_at,
                    'allowed_actions' => $actions,
                ];
            });

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /** @param array{organization_id: int, project_id: int} $context */
    private function allowed(User $actor, string $permission, array $context): bool
    {
        return $this->authorization->can($actor, $permission, $context + ['strict_project_scope' => true]);
    }

    /** @param array{organization_id: int, project_id: int} $context
     *  @return list<string>
     */
    private function qualityActions(User $actor, array $context, string $status): array
    {
        $actions = [];
        if (in_array($status, ['open', 'assigned', 'in_progress', 'rejected'], true)
            && $this->allowed($actor, 'quality-control.defects.resolve', $context)) {
            $actions[] = 'resolve';
        }
        if (in_array($status, ['open', 'assigned', 'rejected'], true)
            && $this->allowed($actor, 'quality-control.defects.resolve', $context)) {
            $actions[] = 'start';
        }
        if ($status === 'ready_for_review'
            && $this->allowed($actor, 'quality-control.defects.verify', $context)) {
            $actions[] = 'verify';
        }
        if ($status === 'ready_for_review'
            && $this->allowed($actor, 'quality-control.defects.reject', $context)) {
            $actions[] = 'reject';
        }

        return $actions;
    }
}
