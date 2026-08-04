<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Models\BudgetingReportSourceCloseRecord;

final class BudgetingReportOptionsService
{
    /** @return list<array{value:string,label:string,period_start:string,period_end:string,scenario_uuid:string,budget_version_uuid:string}> */
    public function availableClosures(int $organizationId, string $reportCode, string $formulaVersion): array
    {
        return BudgetingReportSourceCloseRecord::query()
            ->where('organization_id', $organizationId)
            ->where('report_code', $reportCode)
            ->where('formula_version', $formulaVersion)
            ->where('status', BudgetingReportSourceCloseStatus::APPROVED->value)
            ->where('retained_until', '>', now())
            ->orderByDesc('period_end')
            ->orderByDesc('approved_at')
            ->get(['close_id', 'period_start', 'period_end', 'scenario_identity', 'plan_identity'])
            ->map(static fn (BudgetingReportSourceCloseRecord $close): array => [
                'value' => (string) $close->close_id,
                'label' => sprintf('Период %s — %s', $close->period_start->format('d.m.Y'), $close->period_end->format('d.m.Y')),
                'period_start' => $close->period_start->format('Y-m-d'),
                'period_end' => $close->period_end->format('Y-m-d'),
                'scenario_uuid' => (string) $close->scenario_identity,
                'budget_version_uuid' => (string) $close->plan_identity,
            ])
            ->all();
    }
}
