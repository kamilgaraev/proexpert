<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractPerformanceAct;

final class EstimateFinanceExecutionSnapshot
{
    public static function make(ContractPerformanceAct $act, array $document): array
    {
        $snapshot = ['act' => $act->getAttributes(), 'lines' => $act->lines->sortBy('id')->values()->map(fn ($line) => $line->getAttributes())->all()];
        $snapshot['capacity'] = array_intersect_key($document, array_flip(['contract_id', 'currency', 'side', 'unallocated_amount_with_vat', 'unallocated_amount_without_vat']));
        if ($act->lines->isEmpty()) {
            $snapshot['works'] = $act->completedWorks->sortBy('id')->values()->map(fn ($work) => ['work' => $work->getAttributes(), 'pivot' => $work->pivot->getAttributes()])->all();
        }

        return $snapshot;
    }
}
