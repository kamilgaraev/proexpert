<?php

declare(strict_types=1);

use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;

return [
    'project_margin' => [
        'source' => ProjectMarginManagementPnlComponentSource::class,
        'formula_version' => 'budgeting.project-margin.v1',
        'source_schema_version' => 'budgeting.project-margin.v1',
    ],
    'budget_plan_fact' => [
        'source' => BudgetPlanFactManagementPnlComponentSource::class,
        'formula_version' => 'budgeting.plan-fact.v1',
        'source_schema_version' => 'budgeting.plan-fact.v1',
    ],
    'project_labor_cost' => [
        'source' => ProjectLaborCostManagementPnlComponentSource::class,
        'formula_version' => 'time-tracking.labor-cost.v1',
        'source_schema_version' => 'approved-time-entry-reporting-fact.v1',
    ],
    'payroll_readiness' => [
        'source' => PayrollReadinessManagementPnlComponentSource::class,
        'formula_version' => 'workforce.payroll-readiness.v1',
        'source_schema_version' => 'payroll-readiness-snapshot.v1',
    ],
];
