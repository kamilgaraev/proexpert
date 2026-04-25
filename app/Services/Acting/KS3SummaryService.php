<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\ContractPerformanceAct;

class KS3SummaryService
{
    public function summarize(int $contractId, string $periodStart, string $periodEnd): array
    {
        $previous = $this->approvedQuery($contractId)
            ->where('act_date', '<', $periodStart)
            ->sum('amount');

        $current = $this->approvedQuery($contractId)
            ->whereBetween('act_date', [$periodStart, $periodEnd])
            ->sum('amount');

        $cumulative = $this->approvedQuery($contractId)
            ->where('act_date', '<=', $periodEnd)
            ->sum('amount');

        return [
            'previous_approved_amount' => round((float) $previous, 2),
            'current_approved_amount' => round((float) $current, 2),
            'cumulative_approved_amount' => round((float) $cumulative, 2),
        ];
    }

    private function approvedQuery(int $contractId)
    {
        return ContractPerformanceAct::query()
            ->where('contract_id', $contractId)
            ->where('is_approved', true);
    }
}
