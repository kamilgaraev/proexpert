<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use Illuminate\Validation\ValidationException;

final class EstimateFinanceExecutionSource
{
    public function __construct(private readonly EstimateFinanceExecution $execution, private readonly EstimateFinanceQuery $query) {}

    public function read(Estimate $estimate, ContractPerformanceAct $act): array
    {
        if ((int) $act->project_id !== (int) $estimate->project_id) {
            $this->invalid();
        }
        $report = $this->execution->report($estimate, $this->query->contracts($estimate), (int) $act->id);
        $document = $report['documents'][0] ?? null;
        if (! $document || $document['needs_review'] || $document['side'] === 'unknown'
            || ! preg_match('/^[A-Z]{3}$/', (string) $document['currency'])
            || $document['unallocated_amount_with_vat'] === null
            || FinanceDecimal::compare($document['unallocated_amount_with_vat'], '0') < 0) {
            $this->invalid();
        }
        $net = $document['unallocated_amount_without_vat'];
        if ($net !== null && (FinanceDecimal::compare($net, '0') < 0 || FinanceDecimal::compare($net, $document['unallocated_amount_with_vat']) > 0)) {
            $this->invalid();
        }
        $act->load(['lines' => fn ($builder) => $builder->orderBy('id')]);
        $snapshot = ['act' => $act->getAttributes(), 'lines' => $act->lines->map(fn ($line) => $line->getAttributes())->all()];
        $snapshot['capacity'] = array_intersect_key($document, array_flip(['contract_id', 'currency', 'side', 'unallocated_amount_with_vat', 'unallocated_amount_without_vat']));
        if ($act->lines->isEmpty()) {
            $act->load(['completedWorks' => fn ($builder) => $builder->orderBy('completed_works.id')]);
            $snapshot['works'] = $act->completedWorks->map(fn ($work) => ['work' => $work->getAttributes(), 'pivot' => $work->pivot->getAttributes()])->all();
        }

        return ['act_id' => (int) $act->id, 'contract_id' => $document['contract_id'], 'currency' => $document['currency'],
            'amount_with_vat' => $document['unallocated_amount_with_vat'], 'amount_without_vat' => $document['unallocated_amount_without_vat'],
            'source_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['act_id' => trans_message('estimate_finance.execution_distribution_invalid')]);
    }
}
