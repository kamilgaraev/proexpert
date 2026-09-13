<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use Illuminate\Support\Facades\DB;

final class EstimateFinanceCashLedger
{
    public function __construct(private readonly EstimateFinanceCashSummary $summary) {}

    public function report(Estimate $estimate, array $sources): array
    {
        $sources = array_column($sources, null, 'transaction_id');
        $states = [];
        foreach ($sources as $id => $source) {
            $states[$id] = ['transaction_id' => $id, 'source_hash' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
                'currency' => $source['currency'], 'source_amount' => $source['amount'], 'known_allocated_amount' => '0.00',
                'estimate_allocated_amount' => '0.00', 'requires_review' => $source['amount'] === null || $source['direction_requires_review']];
        }
        $rows = DB::table('estimate_finance_cash_allocations as cash')
            ->join('estimate_finance_allocations as allocation', 'allocation.id', '=', 'cash.allocation_id')
            ->join('estimates as estimate', 'estimate.id', '=', 'allocation.estimate_id')
            ->whereIn('cash.payment_transaction_id', array_keys($sources))
            ->select(['cash.*', 'allocation.key as allocation_key', 'allocation.condition_version', 'allocation.contract_id',
                'allocation.estimate_item_id', 'allocation.resource_id', 'allocation.organization_id as allocation_org',
                'allocation.estimate_id as allocation_estimate', 'allocation.currency as allocation_currency',
                'estimate.organization_id as estimate_org', 'estimate.project_id as estimate_project'])->orderBy('cash.id')->get();
        $allocations = [];
        $facts = [];
        $positionFacts = [];
        foreach ($rows as $row) {
            $source = $sources[$row->payment_transaction_id];
            $state = &$states[$row->payment_transaction_id];
            $validScope = (int) $row->organization_id === (int) $estimate->organization_id
                && (int) $row->project_id === (int) $estimate->project_id && (int) $row->allocation_org === (int) $estimate->organization_id
                && (int) $row->estimate_org === (int) $estimate->organization_id && (int) $row->estimate_project === (int) $estimate->project_id
                && (int) $row->estimate_id === (int) $row->allocation_estimate && (int) $row->contract_id === $source['contract_id']
                && $row->currency === $source['currency'] && $row->allocation_currency === $source['currency'];
            if (! $validScope) {
                $state['requires_review'] = true;
                unset($state);
                continue;
            }
            $changed = $row->source_hash !== $state['source_hash'] || ($source['amount'] !== null
                && ((FinanceDecimal::compare($source['amount'], '0') >= 0 && FinanceDecimal::compare($row->amount, '0') < 0)
                    || (FinanceDecimal::compare($source['amount'], '0') < 0 && FinanceDecimal::compare($row->amount, '0') > 0)));
            if ($changed) {
                $state['requires_review'] = true;
            } else {
                $state['known_allocated_amount'] = FinanceDecimal::add($state['known_allocated_amount'], $row->amount);
            }
            if ((int) $row->estimate_id !== (int) $estimate->id) {
                unset($state);
                continue;
            }
            $state['estimate_allocated_amount'] = $changed ? null : ($state['estimate_allocated_amount'] === null ? null
                : FinanceDecimal::add($state['estimate_allocated_amount'], $row->amount));
            $key = $row->resource_id ? 'r:'.$row->resource_id : 'i:'.$row->estimate_item_id;
            $allocations[] = ['id' => (int) $row->id, 'key' => $row->key, 'estimate_id' => (int) $row->estimate_id,
                'allocation_key' => $row->allocation_key, 'condition_version' => (int) $row->condition_version, 'target_key' => $key,
                'transaction_id' => (int) $row->payment_transaction_id, 'version' => (int) $row->version,
                'amount' => $row->amount, 'currency' => $row->currency, 'source_changed' => $changed];
            $fact = array_replace($source, ['transaction_id' => $row->id, 'amount' => $row->amount,
                'native_transaction_id' => $source['transaction_id'], 'target_key' => $key, 'estimate_item_id' => (int) $row->estimate_item_id,
                'direction_requires_review' => $source['direction_requires_review'] || $changed]);
            $facts[] = $fact;
            unset($state);
        }
        foreach ($states as &$state) {
            $remaining = $state['source_amount'] === null ? null : FinanceDecimal::subtract($state['source_amount'], $state['known_allocated_amount']);
            if ($remaining !== null && ((FinanceDecimal::compare($state['source_amount'], '0') >= 0 && FinanceDecimal::compare($remaining, '0') < 0)
                || (FinanceDecimal::compare($state['source_amount'], '0') < 0 && FinanceDecimal::compare($remaining, '0') > 0))) {
                $state['requires_review'] = true;
            }
            $state['allocated_amount'] = $state['requires_review'] ? null : $state['known_allocated_amount'];
            $state['remaining_amount'] = $state['requires_review'] ? null : $remaining;
        }
        unset($state);
        foreach ($facts as &$fact) {
            $fact['direction_requires_review'] = $fact['direction_requires_review'] || $states[$fact['native_transaction_id']]['requires_review'];
            $positionFacts[$fact['target_key']][] = $fact;
        }
        unset($fact);
        $positions = [];
        foreach ($positionFacts as $key => $position) {
            $positions[] = ['estimate_id' => (int) $estimate->id, 'target_key' => $key, 'totals' => $this->summary->calculate($position)['totals']];
        }

        return ['scope' => 'allocated_positions', 'sources' => array_values($states), 'allocations' => $allocations,
            'totals' => $this->summary->calculate($facts)['totals'], 'positions' => $positions,
            'sections' => $this->sections($estimate, $facts)];
    }

    private function sections(Estimate $estimate, array $facts): array
    {
        if ($facts === []) {
            return [];
        }
        $items = DB::table('estimate_items')->where('estimate_id', $estimate->id)
            ->whereIn('id', array_unique(array_column($facts, 'estimate_item_id')))
            ->pluck('estimate_section_id', 'id');
        $tree = DB::table('estimate_sections')->where('estimate_id', $estimate->id)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_section_id', 'name'])->keyBy('id');
        $paths = $groups = [];
        foreach ($facts as $fact) {
            $sectionId = $items->get($fact['estimate_item_id']);
            $pathKey = $sectionId ?? 'none';
            if (! isset($paths[$pathKey])) {
                $path = [];
                $current = $sectionId;
                $invalid = false;
                while ($current !== null) {
                    $section = $tree->get($current);
                    if ($section === null || isset($path[$current])) {
                        $invalid = true;
                        break;
                    }
                    $path[$current] = $section;
                    $current = $section->parent_section_id;
                }
                $paths[$pathKey] = ['path' => $path, 'invalid' => $invalid];
            }
            $path = $paths[$pathKey];
            foreach ($path['path'] ?: [null] as $section) {
                $key = $section?->id ?? 'none';
                $groups[$key] ??= ['estimate_id' => (int) $estimate->id, 'section_id' => $section === null ? null : (int) $section->id,
                    'name' => $section?->name, 'parent_section_id' => $section?->parent_section_id,
                    'hierarchy_requires_review' => false, 'facts' => []];
                $groups[$key]['hierarchy_requires_review'] = $groups[$key]['hierarchy_requires_review']
                    || $path['invalid'] || ! $items->has($fact['estimate_item_id']);
                $groups[$key]['facts'][] = $fact;
            }
        }
        foreach ($groups as &$group) {
            $group['totals'] = $this->summary->calculate($group['facts'])['totals'];
            unset($group['facts']);
        }
        unset($group);

        return array_values($groups);
    }
}
