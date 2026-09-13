<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\ContractPerformanceAct;

final class EstimateFinanceExecutionSnapshot
{
    public static function hash(array $snapshot): string
    {
        if (in_array($snapshot['act']['status'] ?? null, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true)) {
            $snapshot['act']['status'] = ContractPerformanceAct::STATUS_APPROVED;
        }
        foreach (['updated_at', 'signed_file_id', 'signed_by_user_id', 'signed_at', 'locked_by_user_id', 'locked_at'] as $field) {
            unset($snapshot['act'][$field]);
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

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
