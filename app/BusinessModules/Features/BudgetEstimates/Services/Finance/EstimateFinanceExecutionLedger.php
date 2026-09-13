<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\Estimate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EstimateFinanceExecutionLedger
{
    public function merge(Estimate $estimate, array $report, Collection $acts): array
    {
        $report['distributions'] = [];
        if ($acts->isEmpty()) {
            return $report;
        }
        $byAct = $acts->keyBy('id');
        $records = DB::table('estimate_finance_execution_allocations as fact')
            ->join('estimate_finance_allocations as conditions', 'conditions.id', '=', 'fact.allocation_id')
            ->join('estimates', 'estimates.id', '=', 'fact.estimate_id')
            ->leftJoin('estimate_items as items', 'items.id', '=', 'conditions.estimate_item_id')
            ->whereIn('fact.performance_act_id', $acts->pluck('id'))
            ->orderBy('fact.id')->select('fact.*', 'conditions.key as allocation_key', 'conditions.contract_id',
                'conditions.estimate_item_id', 'conditions.resource_id', 'conditions.organization_id as condition_org',
                'conditions.estimate_id as condition_estimate', 'conditions.currency as condition_currency',
                'estimates.organization_id as estimate_org', 'estimates.project_id as estimate_project',
                'items.estimate_id as item_estimate', 'items.name as title')->get()->groupBy('performance_act_id');
        foreach ($report['documents'] as &$document) {
            $rows = $records->get($document['id'], collect());
            if ($rows->isEmpty()) {
                continue;
            }
            $hash = hash('sha256', json_encode(EstimateFinanceExecutionSnapshot::make($byAct->get($document['id']), $document), JSON_THROW_ON_ERROR));
            $gross = '0.00';
            $net = '0.00';
            $inEstimate = '0.00';
            $invalid = false;
            $entries = [];
            foreach ($rows as $row) {
                $scoped = (int) $row->organization_id === (int) $estimate->organization_id && (int) $row->project_id === (int) $estimate->project_id
                    && (int) $row->condition_org === (int) $estimate->organization_id && (int) $row->estimate_org === (int) $estimate->organization_id
                    && (int) $row->estimate_project === (int) $estimate->project_id && (int) $row->condition_estimate === (int) $row->estimate_id
                    && (int) $row->item_estimate === (int) $row->estimate_id && (int) $row->contract_id === (int) $document['contract_id'];
                if (! $scoped) {
                    $invalid = true;
                    continue;
                }
                if ((int) $row->estimate_id === (int) $estimate->id) {
                    $report['distributions'][] = ['act_id' => (int) $document['id'], 'allocation_key' => $row->allocation_key,
                        'distribution_key' => $row->key, 'version' => (int) $row->version,
                        'amount' => $row->amount_with_vat, 'quantity' => $row->quantity];
                }
                if (FinanceDecimal::compare($row->amount_with_vat, '0') === 0 && ($row->quantity === null || FinanceDecimal::compare($row->quantity, '0') === 0)) {
                    continue;
                }
                $review = ! hash_equals($hash, $row->source_hash) || $row->currency !== $document['currency'] || $row->condition_currency !== $row->currency;
                $invalid = $invalid || $review;
                $gross = FinanceDecimal::add($gross, $row->amount_with_vat);
                $net = $net === null || $row->amount_without_vat === null ? null : FinanceDecimal::add($net, $row->amount_without_vat);
                if ((int) $row->estimate_id !== (int) $estimate->id) {
                    continue;
                }
                $inEstimate = FinanceDecimal::add($inEstimate, $row->amount_with_vat);
                $entries[] = ['source_type' => 'act_distribution', 'source_id' => (int) $row->id, 'distribution_key' => $row->key,
                    'item_id' => (int) $row->estimate_item_id, 'resource_id' => $row->resource_id === null ? null : (int) $row->resource_id,
                    'estimate_id' => (int) $row->estimate_id, 'title' => $row->title, 'quantity' => $review ? null : $row->quantity,
                    'amount_with_vat' => $review ? null : $row->amount_with_vat, 'amount_without_vat' => $review ? null : $row->amount_without_vat,
                    'saved_quantity' => $row->quantity, 'saved_amount_with_vat' => $row->amount_with_vat, 'saved_amount_without_vat' => $row->amount_without_vat,
                    'currency' => $row->currency, 'allocation_key' => $row->allocation_key, 'condition_version' => (int) $row->condition_version,
                    'version' => (int) $row->version, 'act_id' => $document['id'], 'contract_id' => $document['contract_id'],
                    'side' => $document['side'], 'date' => $document['date'], 'requires_review' => $review];
            }
            $remaining = $document['unallocated_amount_with_vat'] === null ? null : FinanceDecimal::subtract($document['unallocated_amount_with_vat'], $gross);
            $remainingNet = $document['unallocated_amount_without_vat'] === null || $net === null ? null : FinanceDecimal::subtract($document['unallocated_amount_without_vat'], $net);
            $invalid = $invalid || ($remaining !== null && FinanceDecimal::compare($remaining, '0') < 0)
                || ($remainingNet !== null && (FinanceDecimal::compare($remainingNet, '0') < 0 || ($remaining !== null && FinanceDecimal::compare($remainingNet, $remaining) > 0)));
            if ($invalid) {
                foreach ($entries as &$entry) {
                    $entry['requires_review'] = true;
                    $entry['quantity'] = $entry['amount_with_vat'] = $entry['amount_without_vat'] = null;
                }
                unset($entry);
            }
            array_push($report['rows'], ...$entries);
            $document['manual_amount_with_vat'] = $invalid ? null : FinanceDecimal::value($gross);
            $document['estimate_amount_with_vat'] = $invalid ? null : FinanceDecimal::add($document['estimate_amount_with_vat'], $inEstimate);
            $document['unallocated_amount_with_vat'] = $invalid ? null : $remaining;
            $document['unallocated_amount_without_vat'] = $invalid ? null : $remainingNet;
            $document['needs_review'] = $document['needs_review'] || $invalid;
        }
        unset($document);

        return $report;
    }
}
