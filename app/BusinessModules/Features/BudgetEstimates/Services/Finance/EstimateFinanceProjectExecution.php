<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

final class EstimateFinanceProjectExecution
{
    public function __construct(private readonly EstimateFinanceExecutionSummary $summary) {}

    public function combine(array $reports, string $basis, bool $available): array
    {
        if (! $available) {
            return ['available' => false, 'rows' => null, 'documents' => null];
        }
        $facts = [];
        $documents = [];
        $targets = [];
        $allocations = [];
        $sections = [];
        foreach ($reports as $report) {
            if (! ($report['execution']['available'] ?? false)) {
                return ['available' => false, 'rows' => null, 'documents' => null];
            }
            foreach ($report['rows'] as $target) {
                $targets[$target['key']] = $target;
                foreach ($target['allocations'] as $allocation) {
                    $allocations[$allocation['key']] = $allocation;
                }
            }
            foreach ($report['sections'] as $section) {
                $sections[$section['id']] = $section;
            }
            foreach ($report['execution']['rows'] as $fact) {
                $facts[$fact['act_id'].':'.$fact['source_type'].':'.$fact['source_id']] = $fact;
            }
            foreach ($report['execution']['documents'] as $document) {
                $documents[$document['id']] ??= $document + ['estimate_amounts' => []];
                $documents[$document['id']]['estimate_amounts'][$report['estimate_id']] = [
                    'estimate_id' => $report['estimate_id'], 'name' => $report['name'],
                    'amount_with_vat' => $document['estimate_amount_with_vat'],
                ];
            }
        }
        foreach ($documents as &$document) {
            $amount = '0';
            foreach ($document['estimate_amounts'] as $estimateAmount) {
                $amount = FinanceDecimal::add($amount, $estimateAmount['amount_with_vat']);
            }
            $document['estimate_amount_with_vat'] = FinanceDecimal::value($amount);
            $document['estimate_amounts'] = array_values($document['estimate_amounts']);
        }
        unset($document);

        return ['available' => true, 'rows' => array_values($facts), 'documents' => array_values($documents),
            'summary' => $this->summary->calculate(array_values($facts), array_values($allocations), $targets, array_values($sections), $basis)];
    }
}
