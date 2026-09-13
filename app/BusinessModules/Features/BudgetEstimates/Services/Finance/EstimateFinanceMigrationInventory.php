<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EstimateFinanceMigrationInventory
{
    public function page(?int $organizationId = null, int $afterId = 0, int $limit = 500): array
    {
        if (($organizationId !== null && $organizationId < 1) || $afterId < 0 || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('invalid_inventory_scope');
        }
        $links = DB::table('contract_estimate_items as link')
            ->leftJoin('estimates as estimate', 'estimate.id', '=', 'link.estimate_id')
            ->leftJoin('contracts as contract', 'contract.id', '=', 'link.contract_id')
            ->leftJoin('estimate_items as item', 'item.id', '=', 'link.estimate_item_id')
            ->when($organizationId !== null, fn ($query) => $query->where('estimate.organization_id', $organizationId))
            ->where('link.id', '>', $afterId)->orderBy('link.id')->limit($limit + 1)
            ->get(['link.id', 'link.estimate_id', 'link.contract_id', 'link.estimate_item_id', 'link.quantity', 'link.amount',
                'link.amount_without_vat', 'link.notes', 'link.created_at', 'link.updated_at', 'link.finance_managed',
                'estimate.organization_id', 'estimate.project_id', 'contract.organization_id as contract_organization_id',
                'contract.project_id as contract_project_id', 'item.estimate_id as item_estimate_id', 'item.is_not_accounted', 'item.parent_work_id']);
        $page = $links->take($limit);
        $allocations = DB::table('estimate_finance_allocations')->whereIn('contract_estimate_item_id', $page->pluck('id'))
            ->orderBy('id')->get(['id', 'key', 'organization_id', 'estimate_id', 'contract_id', 'estimate_item_id', 'contract_estimate_item_id'])
            ->groupBy('contract_estimate_item_id');
        $rows = [];
        foreach ($page as $link) {
            $preserved = array_intersect_key((array) $link, array_flip(['id', 'estimate_id', 'contract_id', 'estimate_item_id',
                'quantity', 'amount', 'amount_without_vat', 'notes', 'created_at', 'updated_at']));
            $conditions = $allocations->get($link->id, collect());
            $issues = [];
            if ($link->organization_id === null) {
                $issues[] = 'estimate_missing';
            }
            if ($link->contract_organization_id === null || $link->organization_id !== $link->contract_organization_id || $link->project_id !== $link->contract_project_id) {
                $issues[] = 'contract_outside_scope_or_missing';
            }
            if ($link->item_estimate_id === null || $link->item_estimate_id !== $link->estimate_id) {
                $issues[] = 'position_outside_scope_or_missing';
            }
            if ($link->finance_managed && $conditions->isEmpty()) {
                $issues[] = 'managed_link_without_conditions';
            }
            foreach ($conditions as $condition) {
                if ($condition->organization_id !== $link->organization_id || $condition->estimate_id !== $link->estimate_id
                    || $condition->contract_id !== $link->contract_id || $condition->estimate_item_id !== $link->estimate_item_id) {
                    $issues[] = 'condition_scope_mismatch';
                }
            }
            $rows[] = ['organization_id' => $link->organization_id, 'project_id' => $link->project_id,
                'legacy_link_id' => $link->id, 'preserved' => $preserved,
                'preserved_values_hash' => hash('sha256', json_encode($preserved, JSON_THROW_ON_ERROR)),
                'finance_managed' => (bool) $link->finance_managed, 'allocation_ids' => $conditions->pluck('id')->all(),
                'allocation_keys' => $conditions->pluck('key')->all(), 'excluded' => (bool) $link->is_not_accounted,
                'parent_work_id' => $link->parent_work_id, 'issues' => array_values(array_unique($issues))];
        }

        return ['read_only' => true, 'kind' => 'legacy_preservation_inventory', 'rows' => $rows,
            'next_cursor' => $links->count() > $limit ? $page->last()->id : null];
    }
}
