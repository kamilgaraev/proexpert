<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\PerformanceActLine;
use App\Models\User;
use App\Services\Acting\ActingQuantityStatus;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class ContractEstimateOperationalProgress
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly UserProjectAccessService $projects) {}

    public function prepare(Contract $contract, Collection $links, ?User $actor): void
    {
        if ($actor !== null && (int) $actor->current_organization_id !== (int) $contract->organization_id) {
            throw new AuthorizationException;
        }
        if ($actor !== null && ! $this->projects->queryAccessibleProjects($actor, (int) $contract->organization_id)->whereKey($contract->project_id)->exists()) {
            throw new AuthorizationException;
        }
        if ($links->contains(fn ($link): bool => (int) $link->contract_id !== (int) $contract->id)) {
            throw new AuthorizationException;
        }
        $context = ['context_type' => 'project', 'project_id' => (int) $contract->project_id,
            'organization_id' => (int) $contract->organization_id];
        $can = fn (string $permission): bool => $actor !== null && $this->authorization->can($actor, $permission, $context);
        $canViewWorks = $can('contracts.completed_works.view');
        $canViewActs = $can('contracts.performance_acts.view');
        $canEdit = $can('contracts.edit');
        $canCreateAct = $can('contracts.performance_acts.create');
        $itemIds = $links->pluck('estimate_item_id')->unique()->values()->all();
        $actual = [];
        $acting = [];

        if ($itemIds !== [] && $canViewWorks) {
            $actual = CompletedWork::query()->effectiveForSchedule()
                ->where('organization_id', $contract->organization_id)
                ->where('project_id', $contract->project_id)
                ->where('contract_id', $contract->id)
                ->whereIn('estimate_item_id', $itemIds)
                ->selectRaw('estimate_item_id, SUM(completed_quantity) AS actual_quantity')
                ->groupBy('estimate_item_id')->pluck('actual_quantity', 'estimate_item_id')->all();
        }
        if ($itemIds !== [] && $canViewActs) {
            $lines = PerformanceActLine::query()->with('performanceAct')
                ->whereIn('estimate_item_id', $itemIds)
                ->whereHas('performanceAct', function ($query) use ($contract): void {
                    $query->where('contract_id', $contract->id)->where('project_id', $contract->project_id);
                })->get();
            foreach ($lines as $line) {
                if (ActingQuantityStatus::isReleased($line->performanceAct)) {
                    continue;
                }
                $field = ActingQuantityStatus::isApproved($line->performanceAct) ? 'approved_acted_quantity' : 'reserved_quantity';
                $acting[$line->estimate_item_id][$field] = ($acting[$line->estimate_item_id][$field] ?? 0.0) + (float) $line->quantity;
            }
        }

        foreach ($links as $link) {
            $link->setRelation('operationalProgress', [
                'can_view_works' => $canViewWorks,
                'can_view_acts' => $canViewActs,
                'can_edit' => $canEdit,
                'can_create_act' => $canCreateAct,
                'actual_quantity' => $canViewWorks ? round((float) ($actual[$link->estimate_item_id] ?? 0), 4) : null,
                'reserved_quantity' => $canViewActs ? round($acting[$link->estimate_item_id]['reserved_quantity'] ?? 0.0, 4) : null,
                'approved_acted_quantity' => $canViewActs ? round($acting[$link->estimate_item_id]['approved_acted_quantity'] ?? 0.0, 4) : null,
            ]);
        }
    }
}
