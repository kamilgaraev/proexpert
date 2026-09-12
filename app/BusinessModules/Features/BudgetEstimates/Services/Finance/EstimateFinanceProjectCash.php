<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceProjectCash
{
    public function __construct(private readonly EstimateFinanceCashSummary $summary) {}

    public function combine(array $reports, bool $available): array
    {
        $denied = ['available' => false, 'scope' => 'linked_contracts', 'sources' => null, 'documents' => null];
        if (! $available) {
            return $denied;
        }
        $sources = [];
        $documents = [];
        $estimateIds = [];
        $distributionSources = [];
        $allocations = [];
        $positions = [];
        foreach ($reports as $report) {
            if (! ($report['cash']['available'] ?? false)) {
                return $denied;
            }
            foreach ($report['cash']['sources'] as $source) {
                $sources[$source['transaction_id']] = $source;
            }
            foreach ($report['cash']['distribution']['sources'] ?? [] as $state) {
                unset($state['estimate_allocated_amount']);
                $distributionSources[$state['transaction_id']] = $state;
            }
            foreach ($report['cash']['distribution']['allocations'] ?? [] as $allocation) {
                $allocations[$allocation['id']] = $allocation;
            }
            foreach ($report['cash']['distribution']['positions'] ?? [] as $position) {
                $positions[$position['estimate_id'].':'.$position['target_key']] = $position;
            }
            foreach ($report['cash']['documents'] as $document) {
                $documents[$document['id']] = $document;
                $estimateIds[$document['id']][$report['estimate_id']] = true;
            }
        }
        foreach ($documents as $id => &$document) {
            $document['linked_estimate_ids'] = array_keys($estimateIds[$id]);
        }
        unset($document);
        ksort($sources);
        ksort($documents);
        $facts = [];
        foreach ($allocations as $allocation) {
            $source = $sources[$allocation['transaction_id']];
            $facts[] = array_replace($source, ['transaction_id' => $allocation['id'], 'amount' => $allocation['amount'],
                'direction_requires_review' => $source['direction_requires_review'] || $allocation['source_changed']
                    || ($distributionSources[$allocation['transaction_id']]['requires_review'] ?? true)]);
        }
        $sources = array_values($sources);

        return ['available' => true, 'scope' => 'linked_contracts', 'sources' => $sources,
            'documents' => array_values($documents), 'summary' => $this->summary->calculate($sources),
            'distribution' => ['scope' => 'allocated_positions', 'sources' => array_values($distributionSources),
                'allocations' => array_values($allocations), 'positions' => array_values($positions), 'totals' => $this->summary->calculate($facts)['totals']]];
    }
}
