<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

final readonly class BudgetPlanFactManagementPnlComponentSource extends ProjectFinanceManagementPnlComponentSource
{
    public function componentCode(): string
    {
        return 'budget_plan_fact';
    }
}
