<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\AdvanceAccountTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceOwnCostReport
{
    public function __construct(private readonly EstimateFinanceAccess $access, private readonly AuthorizationService $authorization) {}

    public function report(User $actor, int $projectId, ?int $estimateId, string $basis): array
    {
        $this->access->project($actor, $projectId);
        if ($estimateId !== null) {
            $this->access->estimate($actor, $projectId, $estimateId);
        }
        if (! in_array($basis, ['with_vat', 'without_vat'], true)) {
            throw ValidationException::withMessages(['basis' => trans_message('estimate_finance.invalid')]);
        }
        $canViewSource = $this->authorization->can($actor, 'advance_transactions.view', [
            'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => (int) $actor->current_organization_id,
        ]);
        $costs = DB::table('estimate_finance_own_costs')->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->orderBy('id')->get()->keyBy('id');
        $native = $canViewSource ? AdvanceAccountTransaction::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereIn('id', $costs->pluck('advance_transaction_id')->filter())->get()->keyBy('id') : collect();
        $ledger = DB::table('estimate_finance_own_cost_allocations as ledger')
            ->leftJoin('estimate_finance_allocations as allocation', 'allocation.id', '=', 'ledger.allocation_id')
            ->leftJoin('estimates as estimate', 'estimate.id', '=', 'ledger.estimate_id')
            ->leftJoin('estimate_items as item', fn ($join) => $join->on('item.id', '=', 'allocation.estimate_item_id')
                ->on('item.estimate_id', '=', 'allocation.estimate_id'))
            ->leftJoin('estimate_item_resources as resource', fn ($join) => $join->on('resource.id', '=', 'allocation.resource_id')
                ->on('resource.estimate_item_id', '=', 'item.id'))
            ->whereIn('ledger.own_cost_id', $costs->keys())->orderBy('ledger.id')
            ->get(['ledger.*', 'allocation.key as allocation_key', 'allocation.organization_id as allocation_org',
                'allocation.estimate_id as allocation_estimate', 'allocation.source as allocation_source', 'allocation.side', 'allocation.currency',
                'allocation.contract_id', 'allocation.estimate_item_id', 'allocation.resource_id', 'allocation.condition_version as current_condition_version',
                'estimate.organization_id as estimate_org', 'estimate.project_id', 'item.name as item_name', 'resource.name as resource_name',
                'item.estimate_section_id as section_id']);
        $sources = [];
        $validRows = [];
        foreach ($costs as $cost) {
            $available = $cost->source_type !== 'advance_expense' || $canViewSource;
            if (! $available) {
                $sources[$cost->id] = ['id' => (int) $cost->id, 'key' => $cost->key, 'available' => false,
                    'currency' => '', 'amount' => null, 'requires_review' => true, 'status' => $cost->status];
                continue;
            }
            $snapshot = json_decode($cost->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $changed = false;
            if ($cost->source_type === 'advance_expense') {
                $document = $native->get($cost->advance_transaction_id)?->only(['id', 'organization_id', 'project_id', 'type', 'amount', 'reporting_status',
                    'approved_at', 'approved_by_user_id', 'document_number', 'document_date', 'description', 'cost_category_id']);
                $changed = $document === null || json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) != ($snapshot['document'] ?? null);
            }
            $sources[$cost->id] = ['id' => (int) $cost->id, 'key' => $cost->key, 'available' => true,
                'currency' => $cost->currency, 'status' => $cost->status, 'version' => (int) $cost->version,
                'source_hash' => $cost->source_hash, 'source_type' => $cost->source_type,
                'advance_transaction_id' => $cost->advance_transaction_id === null ? null : (int) $cost->advance_transaction_id,
                'expense_date' => $cost->expense_date, 'basis' => $cost->basis, 'category_id' => (int) $cost->cost_category_id,
                'category_name' => $snapshot['category_name'] ?? null, 'vat_mode' => $cost->vat_mode, 'vat_rate' => $cost->vat_rate,
                'saved_amount' => $cost->amount, 'saved_without_vat' => $cost->amount_without_vat,
                'requires_review' => $changed, 'source_changed' => $changed, 'allocated_amount' => '0.00', 'allocated_without_vat' => '0.00'];
        }
        foreach ($ledger as $row) {
            $cost = $costs->get($row->own_cost_id);
            if (! $sources[$cost->id]['available']) {
                if ($estimateId === null || (int) $row->estimate_id === $estimateId) {
                    $sources[$cost->id]['allocation_access_restricted'] = true;
                }
                continue;
            }
            if ((int) $row->allocation_org !== (int) $actor->current_organization_id || (int) $row->estimate_org !== (int) $actor->current_organization_id
                || (int) $row->project_id !== $projectId || (int) $row->allocation_estimate !== (int) $row->estimate_id
                || $row->allocation_source !== 'own' || $row->side !== 'cost' || $row->contract_id !== null || $row->currency !== $cost->currency) {
                $sources[$cost->id]['requires_review'] = true;
                continue;
            }
            if ((int) $row->source_version !== (int) $cost->version) {
                $sources[$cost->id]['requires_review'] = true;
            }
            $source = &$sources[$cost->id];
            $source['allocated_amount'] = FinanceDecimal::add($source['allocated_amount'], $row->amount);
            $source['allocated_without_vat'] = $source['allocated_without_vat'] === null || $row->amount_without_vat === null ? null
                : FinanceDecimal::add($source['allocated_without_vat'], $row->amount_without_vat);
            unset($source);
            if ($estimateId === null || (int) $row->estimate_id === $estimateId) {
                $validRows[] = $row;
            }
        }
        $sourceTotals = $allocatedTotals = $positions = [];
        foreach ($sources as &$source) {
            if (($source['allocation_access_restricted'] ?? false) && $source['status'] !== 'voided') {
                $this->add($allocatedTotals, '', null);
            }
            if ($source['available']) {
                if (FinanceDecimal::compare($source['allocated_amount'], $source['saved_amount']) > 0
                    || ($source['saved_without_vat'] !== null && $source['allocated_without_vat'] !== null
                        && FinanceDecimal::compare($source['allocated_without_vat'], $source['saved_without_vat']) > 0)) {
                    $source['requires_review'] = true;
                }
                $source['amount'] = $source['requires_review'] ? null : $source[$basis === 'with_vat' ? 'saved_amount' : 'saved_without_vat'];
                $source['remaining_amount'] = $source['requires_review'] ? null : FinanceDecimal::subtract($source['saved_amount'], $source['allocated_amount']);
                $source['remaining_without_vat'] = $source['requires_review'] || $source['saved_without_vat'] === null || $source['allocated_without_vat'] === null
                    ? null : FinanceDecimal::subtract($source['saved_without_vat'], $source['allocated_without_vat']);
            }
            if ($source['status'] !== 'voided') {
                $this->add($sourceTotals, $source['currency'], $source['amount']);
            }
        }
        unset($source);
        $rows = [];
        foreach ($validRows as $row) {
            $source = $sources[$row->own_cost_id];
            $targetKey = $row->resource_id ? 'r:'.$row->resource_id : 'i:'.$row->estimate_item_id;
            $amount = $source['requires_review'] ? null : ($basis === 'with_vat' ? $row->amount : $row->amount_without_vat);
            $rows[] = ['id' => (int) $row->id, 'key' => $row->key, 'cost_key' => $source['key'], 'estimate_id' => (int) $row->estimate_id,
                'allocation_key' => $row->allocation_key, 'target_key' => $targetKey, 'name' => $row->resource_id ? $row->resource_name : $row->item_name, 'section_id' => $row->section_id,
                'version' => (int) $row->version, 'source_version' => (int) $row->source_version, 'condition_version' => (int) $row->current_condition_version,
                'recorded_condition_version' => (int) $row->condition_version,
                'currency' => $source['currency'], 'amount' => $amount, 'saved_amount' => $row->amount, 'saved_without_vat' => $row->amount_without_vat,
                'requires_review' => $source['requires_review'], 'status' => $source['status']];
            if ($source['status'] !== 'voided') {
                $this->add($allocatedTotals, $source['currency'], $amount);
                $position = $row->estimate_id.':'.$targetKey;
                $positions[$position] ??= ['estimate_id' => (int) $row->estimate_id, 'target_key' => $targetKey, 'totals' => []];
                $this->add($positions[$position]['totals'], $source['currency'], $amount);
            }
        }

        return ['basis' => $basis, 'source_scope' => 'project', 'allocation_scope' => $estimateId === null ? 'project' : 'estimate',
            'sources' => array_values($sources), 'rows' => $rows, 'source_totals' => $sourceTotals,
            'allocated_totals' => $allocatedTotals, 'positions' => array_values($positions),
            'sections' => $this->sections($rows, (int) $actor->current_organization_id, $projectId)];
    }

    private function sections(array $rows, int $organizationId, int $projectId): array
    {
        $tree = DB::table('estimate_sections as section')->join('estimates as estimate', 'estimate.id', '=', 'section.estimate_id')
            ->where('estimate.organization_id', $organizationId)->where('estimate.project_id', $projectId)
            ->whereIn('section.estimate_id', array_unique(array_column($rows, 'estimate_id')))
            ->orderBy('section.sort_order')->orderBy('section.id')
            ->get(['section.id', 'section.estimate_id', 'section.parent_section_id', 'section.name'])->keyBy('id');
        $paths = $totals = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'voided') {
                continue;
            }
            $key = $row['estimate_id'].':'.($row['section_id'] ?? 'none');
            if (! isset($paths[$key])) {
                $path = [];
                $current = $row['section_id'];
                $invalid = false;
                while ($current !== null) {
                    $section = $tree->get($current);
                    if (isset($path[$current]) || $section === null || (int) $section->estimate_id !== $row['estimate_id']) {
                        $invalid = true;
                        break;
                    }
                    $path[$current] = $section;
                    $current = $section->parent_section_id;
                }
                $paths[$key] = ['path' => $path, 'invalid' => $invalid];
            }
            $path = $paths[$key];
            foreach ($path['path'] ?: [null] as $section) {
                $sectionKey = $row['estimate_id'].':'.($section?->id ?? 'none');
                $totals[$sectionKey] ??= ['estimate_id' => $row['estimate_id'], 'section_id' => $section === null ? null : (int) $section->id,
                    'name' => $section?->name, 'parent_section_id' => $section?->parent_section_id,
                    'hierarchy_requires_review' => false, 'totals' => []];
                $totals[$sectionKey]['hierarchy_requires_review'] = $totals[$sectionKey]['hierarchy_requires_review'] || $path['invalid'];
                $this->add($totals[$sectionKey]['totals'], $row['currency'], $row['amount']);
            }
        }

        return array_values($totals);
    }

    private function add(array &$totals, string $currency, ?string $amount): void
    {
        $totals[$currency] ??= ['known_amount' => '0.00', 'unknown_count' => 0, 'count' => 0, 'amount' => '0.00'];
        $total = &$totals[$currency];
        $total['count']++;
        if ($amount === null) {
            $total['unknown_count']++;
        } else {
            $total['known_amount'] = FinanceDecimal::add($total['known_amount'], $amount);
        }
        $total['amount'] = $total['unknown_count'] === 0 ? $total['known_amount'] : null;
    }
}
