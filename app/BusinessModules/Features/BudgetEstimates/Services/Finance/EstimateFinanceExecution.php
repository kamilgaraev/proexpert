<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EstimateFinanceExecution
{
    public function __construct(private readonly EstimateFinanceExecutionLedger $ledger) {}

    public function report(Estimate $estimate, array $contracts, ?int $actId = null, bool $includeDistributions = true): array
    {
        $linkedIds = DB::table('contract_estimate_items')->where('estimate_id', $estimate->id)->pluck('contract_id')
            ->merge(DB::table('estimate_finance_allocations')->where('estimate_id', $estimate->id)->whereNotNull('contract_id')->pluck('contract_id'))
            ->unique()->all();
        $contracts = array_intersect_key(array_column($contracts, null, 'id'), array_fill_keys($linkedIds, true));
        $acts = ContractPerformanceAct::query()->whereIn('contract_id', array_keys($contracts))
            ->when($actId !== null, fn ($query) => $query->whereKey($actId))
            ->where('project_id', $estimate->project_id)
            ->whereHas('contract', fn ($query) => $query->where('organization_id', $estimate->organization_id)->where('project_id', $estimate->project_id))
            ->whereIn('status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->whereNull('annulled_at')->with(['lines.estimateItem:id,estimate_id'])->orderBy('id')->get();
        $legacyActs = $acts->filter(fn (ContractPerformanceAct $act): bool => $act->lines->isEmpty());
        $legacyActs->load(['completedWorks' => fn ($query) => $query->where('organization_id', $estimate->organization_id)
            ->where('project_id', $estimate->project_id), 'completedWorks.estimateItem:id,estimate_id']);
        $keys = $acts->flatMap(fn ($act) => $act->lines)->map(fn ($line) => data_get($line->basis_snapshot, 'allocation_key'))
            ->filter(fn ($key) => is_string($key) && Str::isUuid($key))->unique();
        $resourceConditions = $keys->isEmpty() ? collect() : DB::table('estimate_finance_allocations as conditions')
            ->join('estimates', 'estimates.id', '=', 'conditions.estimate_id')
            ->join('estimate_item_resources as resources', 'resources.id', '=', 'conditions.resource_id')
            ->where('conditions.organization_id', $estimate->organization_id)->where('estimates.organization_id', $estimate->organization_id)
            ->where('estimates.project_id', $estimate->project_id)->where('conditions.source', 'contract')
            ->whereIn('conditions.contract_id', array_keys($contracts))->whereIn('conditions.key', $keys)
            ->whereColumn('resources.estimate_item_id', 'conditions.estimate_item_id')
            ->get(['conditions.key', 'conditions.contract_id', 'conditions.estimate_id', 'conditions.estimate_item_id', 'conditions.resource_id', 'conditions.currency'])->keyBy('key');
        $rows = [];
        $documents = [];
        foreach ($acts as $act) {
            $contract = $contracts[$act->contract_id];
            $currency = $act->currency ?: $contract['currency'];
            $mapped = '0.00';
            $mappedNet = '0.00';
            $inEstimate = '0.00';
            $net = '0.00';
            $lines = [];
            foreach ($act->lines as $line) {
                $snapshot = $line->basis_snapshot ?? [];
                $scopedSnapshot = ($snapshot['basis_type'] ?? null) === 'contract_conditions'
                    && (int) ($snapshot['contract_id'] ?? 0) === (int) $act->contract_id
                    && (int) ($snapshot['estimate_item_id'] ?? 0) === (int) $line->estimate_item_id
                    && (int) ($snapshot['estimate_id'] ?? 0) === (int) $line->estimateItem?->estimate_id
                    && ($snapshot['currency'] ?? null) === ($line->currency ?: $currency);
                $base = $scopedSnapshot ? ($snapshot['base_unit_price'] ?? null) : null;
                $condition = $resourceConditions->get($snapshot['allocation_key'] ?? '');
                $resourceId = $scopedSnapshot && $condition !== null
                    && (int) $condition->contract_id === (int) $act->contract_id
                    && (int) $condition->estimate_id === (int) $line->estimateItem?->estimate_id
                    && (int) $condition->estimate_item_id === (int) $line->estimate_item_id
                    && $condition->currency === ($line->currency ?: $currency) ? (int) $condition->resource_id : null;
                if ($line->quantity === null || ! is_string($base) || ! preg_match('/^-?\d+(\.\d+)?$/', $base)) {
                    $base = null;
                }
                $lines[] = ['source_type' => 'act_line', 'source_id' => (int) $line->id,
                    'item_id' => $line->estimate_item_id, 'resource_id' => $resourceId, 'estimate_id' => $line->estimateItem?->estimate_id,
                    'title' => $line->title, 'quantity' => $line->quantity,
                    'amount_with_vat' => $line->amount, 'currency' => $line->currency ?: $currency,
                    'amount_without_vat' => $base === null ? null : FinanceDecimal::multiply((string) $line->quantity, $base, 8),
                    'allocation_key' => $snapshot['allocation_key'] ?? null, 'condition_version' => $snapshot['condition_version'] ?? null];
            }
            if ($act->lines->isEmpty()) {
                foreach ($act->completedWorks as $work) {
                    $lines[] = ['source_type' => 'act_work', 'source_id' => (int) $work->id,
                        'item_id' => $work->estimate_item_id, 'resource_id' => null, 'estimate_id' => $work->estimateItem?->estimate_id,
                        'title' => $work->description, 'quantity' => $work->pivot->included_quantity,
                        'amount_with_vat' => $work->pivot->included_amount, 'currency' => $work->pivot->currency ?: $currency,
                        'amount_without_vat' => null, 'allocation_key' => null, 'condition_version' => null];
                }
            }
            $lineTotal = '0.00';
            $currencyMismatch = false;
            foreach ($lines as $line) {
                if ($line['currency'] !== $currency || $line['amount_with_vat'] === null) {
                    $net = null;
                    $currencyMismatch = $currencyMismatch || $line['currency'] !== $currency;
                } else {
                    $lineTotal = FinanceDecimal::add($lineTotal, (string) $line['amount_with_vat']);
                    $net = $net === null || $line['amount_without_vat'] === null ? null : FinanceDecimal::add($net, $line['amount_without_vat']);
                    if ($line['estimate_id'] !== null) {
                        $mapped = FinanceDecimal::add($mapped, (string) $line['amount_with_vat']);
                        $mappedNet = $mappedNet === null || $line['amount_without_vat'] === null ? null : FinanceDecimal::add($mappedNet, $line['amount_without_vat']);
                    }
                    if ((int) $line['estimate_id'] === (int) $estimate->id) {
                        $inEstimate = FinanceDecimal::add($inEstimate, (string) $line['amount_with_vat']);
                    }
                }
                if ((int) $line['estimate_id'] === (int) $estimate->id) {
                    $rows[] = $line + ['act_id' => (int) $act->id, 'contract_id' => (int) $act->contract_id,
                        'side' => $contract['side'], 'date' => $act->act_date?->format('Y-m-d')];
                }
            }
            $amount = $act->amount === null ? null : FinanceDecimal::value((string) $act->amount);
            $unallocated = $amount === null ? null : FinanceDecimal::subtract($amount, $mapped);
            $documentNet = $amount !== null && $net !== null && FinanceDecimal::compare($lineTotal, $amount) === 0 ? FinanceDecimal::value($net) : null;
            if ($act->amount_without_vat !== null && (FinanceDecimal::compare((string) $act->amount_without_vat, '0') !== 0
                || ($amount !== null && FinanceDecimal::compare($amount, '0') === 0))) {
                $documentNet = FinanceDecimal::value((string) $act->amount_without_vat);
            }
            $documents[] = ['id' => (int) $act->id, 'contract_id' => (int) $act->contract_id,
                'number' => $act->act_document_number, 'date' => $act->act_date?->format('Y-m-d'),
                'status' => $act->status, 'currency' => $currency, 'side' => $contract['side'],
                'amount_with_vat' => $amount,
                'amount_without_vat' => $documentNet,
                'estimate_amount_with_vat' => $inEstimate,
                'unallocated_amount_with_vat' => $unallocated,
                'unallocated_amount_without_vat' => $currencyMismatch || $documentNet === null || $mappedNet === null ? null : FinanceDecimal::subtract($documentNet, $mappedNet),
                'needs_review' => $currencyMismatch || ($unallocated !== null && FinanceDecimal::compare($unallocated, '0') < 0)];
        }

        $report = ['available' => true, 'rows' => $rows, 'documents' => $documents];

        return $includeDistributions ? $this->ledger->merge($estimate, $report, $acts) : $report;
    }
}
