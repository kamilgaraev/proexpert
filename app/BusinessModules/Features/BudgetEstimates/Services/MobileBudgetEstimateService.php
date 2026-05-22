<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\DTOs\MobileBudgetEstimatePage;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MobileBudgetEstimateService
{
    public const STATUSES = [
        'draft',
        'in_review',
        'approved',
        'cancelled',
    ];

    private const IMPACT_STATUSES = [
        'approved',
        'implemented',
        'closed',
    ];

    private const PENDING_CHANGE_STATUSES = [
        'submitted',
        'impact_assessment',
        'internal_review',
        'customer_review',
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function projectSummary(int $organizationId, int $projectId, User $user): array
    {
        $project = $this->findProject($organizationId, $projectId);
        $estimates = $this->estimateListQuery($organizationId, ['project_id' => $project->id])
            ->limit(30)
            ->get();
        $estimateIds = $estimates->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $itemIds = $this->estimateItemIds($estimateIds);
        $changes = $this->linkedChangesQuery($organizationId, (int) $project->id, $itemIds)
            ->limit(20)
            ->get();
        $canApprove = $this->canApprove($user, $organizationId);
        $assignedApprovals = $canApprove
            ? $estimates->filter(fn (Estimate $estimate): bool => $estimate->status === 'in_review')->values()
            : collect();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'budget_amount' => $project->budget_amount !== null ? (float) $project->budget_amount : null,
                'status' => $project->status,
            ],
            'totals' => $this->totalsPayload($estimates),
            'budget' => $this->budgetPayload($project, $estimates, $changes),
            'estimates' => $estimates,
            'linked_change_requests' => $this->changePayloads($changes),
            'assigned_approvals' => $assignedApprovals,
        ];
    }

    public function paginateEstimates(int $organizationId, array $filters, int $perPage): MobileBudgetEstimatePage
    {
        $this->findProject($organizationId, (int) $filters['project_id']);

        $query = $this->estimateListQuery($organizationId, $filters);
        $summary = $this->totalsPayload((clone $query)->get());
        $paginator = $query
            ->orderByDesc('estimate_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return new MobileBudgetEstimatePage($paginator, $summary);
    }

    public function findEstimate(int $organizationId, int $estimateId): Estimate
    {
        $estimate = Estimate::query()
            ->with([
                'project',
                'approvedBy',
                'sections.items.measurementUnit',
                'items.measurementUnit',
            ])
            ->where('organization_id', $organizationId)
            ->whereNull('parent_estimate_id')
            ->whereKey($estimateId)
            ->first();

        if (!$estimate instanceof Estimate) {
            throw new DomainException(trans_message('budget_estimates.mobile.errors.estimate_not_found'));
        }

        return $estimate;
    }

    public function approve(Estimate $estimate, int $userId, ?string $comment): Estimate
    {
        $this->assertInReview($estimate);

        return DB::transaction(function () use ($estimate, $userId, $comment): Estimate {
            $estimate->forceFill([
                'status' => 'approved',
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'metadata' => $this->withApprovalHistory($estimate, 'approve', $userId, $comment),
            ])->save();

            Log::info('budget_estimates.mobile.approved', [
                'estimate_id' => $estimate->id,
                'organization_id' => $estimate->organization_id,
                'project_id' => $estimate->project_id,
                'user_id' => $userId,
            ]);

            return $this->findEstimate((int) $estimate->organization_id, (int) $estimate->id);
        });
    }

    public function requestChanges(Estimate $estimate, int $userId, string $comment): Estimate
    {
        $this->assertInReview($estimate);

        return DB::transaction(function () use ($estimate, $userId, $comment): Estimate {
            $estimate->forceFill([
                'status' => 'draft',
                'approved_by_user_id' => null,
                'approved_at' => null,
                'metadata' => $this->withApprovalHistory($estimate, 'request_changes', $userId, $comment),
            ])->save();

            Log::info('budget_estimates.mobile.changes_requested', [
                'estimate_id' => $estimate->id,
                'organization_id' => $estimate->organization_id,
                'project_id' => $estimate->project_id,
                'user_id' => $userId,
            ]);

            return $this->findEstimate((int) $estimate->organization_id, (int) $estimate->id);
        });
    }

    public function linkedChangesForEstimate(int $organizationId, Estimate $estimate): array
    {
        $itemIds = $this->estimateItemIds([(int) $estimate->id]);

        return $this->changePayloads(
            $this->linkedChangesQuery($organizationId, (int) $estimate->project_id, $itemIds)
                ->limit(20)
                ->get()
        );
    }

    private function estimateListQuery(int $organizationId, array $filters): Builder
    {
        return Estimate::query()
            ->with(['project', 'approvedBy'])
            ->withCount(['sections', 'items'])
            ->where('organization_id', $organizationId)
            ->whereNull('parent_estimate_id')
            ->where('project_id', (int) $filters['project_id'])
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            });
    }

    private function linkedChangesQuery(int $organizationId, int $projectId, array $estimateItemIds): Builder
    {
        $query = ChangeRequest::query()
            ->with(['impact', 'approvals'])
            ->forOrganization($organizationId)
            ->where('project_id', $projectId)
            ->latest('id');

        if ($estimateItemIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($estimateItemIds): void {
            foreach ($estimateItemIds as $itemId) {
                $builder->orWhereJsonContains('affected_estimate_item_ids', $itemId)
                    ->orWhereHas('impact', static function (Builder $impactQuery) use ($itemId): void {
                        $impactQuery->whereJsonContains('affected_estimate_item_ids', $itemId);
                    });
            }
        });
    }

    private function findProject(int $organizationId, int $projectId): Project
    {
        $project = Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->first();

        if (!$project instanceof Project) {
            throw new DomainException(trans_message('budget_estimates.mobile.errors.project_not_found'));
        }

        return $project;
    }

    private function estimateItemIds(array $estimateIds): array
    {
        if ($estimateIds === []) {
            return [];
        }

        return EstimateItem::query()
            ->whereIn('estimate_id', $estimateIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function totalsPayload(EloquentCollection|Collection $estimates): array
    {
        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($estimates as $estimate) {
            if (isset($byStatus[$estimate->status])) {
                $byStatus[$estimate->status]++;
            }
        }

        return [
            'estimates_count' => $estimates->count(),
            'by_status' => $byStatus,
            'total_amount' => round((float) $estimates->sum(fn (Estimate $estimate): float => (float) $estimate->total_amount), 2),
            'total_amount_with_vat' => round((float) $estimates->sum(fn (Estimate $estimate): float => (float) $estimate->total_amount_with_vat), 2),
            'approved_amount_with_vat' => round((float) $estimates
                ->filter(fn (Estimate $estimate): bool => $estimate->status === 'approved')
                ->sum(fn (Estimate $estimate): float => (float) $estimate->total_amount_with_vat), 2),
            'in_review_count' => (int) ($byStatus['in_review'] ?? 0),
        ];
    }

    private function budgetPayload(Project $project, EloquentCollection|Collection $estimates, EloquentCollection $changes): array
    {
        $budgetAmount = $project->budget_amount !== null ? round((float) $project->budget_amount, 2) : null;
        $approvedEstimateAmount = round((float) $estimates
            ->filter(fn (Estimate $estimate): bool => $estimate->status === 'approved')
            ->sum(fn (Estimate $estimate): float => (float) $estimate->total_amount_with_vat), 2);
        $approvedChangeDelta = round((float) $changes
            ->filter(fn (ChangeRequest $change): bool => in_array($change->status, self::IMPACT_STATUSES, true))
            ->sum(fn (ChangeRequest $change): float => (float) ($change->impact?->cost_delta ?? 0)), 2);
        $pendingChangeDelta = round((float) $changes
            ->filter(fn (ChangeRequest $change): bool => in_array($change->status, self::PENDING_CHANGE_STATUSES, true))
            ->sum(fn (ChangeRequest $change): float => (float) ($change->impact?->cost_delta ?? 0)), 2);
        $committedAmount = round($approvedEstimateAmount + $approvedChangeDelta, 2);

        return [
            'project_budget_amount' => $budgetAmount,
            'approved_estimate_amount' => $approvedEstimateAmount,
            'approved_change_delta' => $approvedChangeDelta,
            'pending_change_delta' => $pendingChangeDelta,
            'committed_amount' => $committedAmount,
            'budget_remaining' => $budgetAmount !== null ? round($budgetAmount - $committedAmount, 2) : null,
        ];
    }

    private function changePayloads(EloquentCollection $changes): array
    {
        return $changes
            ->map(fn (ChangeRequest $change): array => [
                'id' => $change->id,
                'project_id' => $change->project_id,
                'change_number' => $change->change_number,
                'title' => $change->title,
                'reason' => $change->reason,
                'status' => $change->status,
                'status_label' => trans_message("budget_estimates.mobile.change_statuses.{$change->status}"),
                'cost_delta' => $change->impact?->cost_delta !== null ? (float) $change->impact->cost_delta : null,
                'schedule_delta_days' => $change->impact?->schedule_delta_days !== null ? (int) $change->impact->schedule_delta_days : null,
                'requires_estimate_revision' => (bool) ($change->impact?->requires_estimate_revision ?? false),
                'affected_estimate_item_ids' => array_values($change->affected_estimate_item_ids ?? []),
                'created_at' => $change->created_at?->toIso8601String(),
                'approved_at' => $change->approved_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function canApprove(User $user, int $organizationId): bool
    {
        return $this->authorizationService->can(
            $user,
            'budget-estimates.approve',
            ['organization_id' => $organizationId]
        );
    }

    private function assertInReview(Estimate $estimate): void
    {
        if ($estimate->status !== 'in_review') {
            throw new DomainException(trans_message('budget_estimates.mobile.errors.status_transition_forbidden'));
        }
    }

    private function withApprovalHistory(Estimate $estimate, string $action, int $userId, ?string $comment): array
    {
        $metadata = is_array($estimate->metadata) ? $estimate->metadata : [];
        $history = is_array($metadata['mobile_approval_history'] ?? null)
            ? $metadata['mobile_approval_history']
            : [];
        $history[] = [
            'action' => $action,
            'user_id' => $userId,
            'comment' => $comment,
            'created_at' => now()->toIso8601String(),
        ];
        $metadata['mobile_approval_history'] = array_values($history);

        return $metadata;
    }
}
