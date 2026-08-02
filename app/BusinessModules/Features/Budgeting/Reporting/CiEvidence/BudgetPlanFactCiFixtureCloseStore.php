<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\CiEvidence;

use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use LogicException;

final readonly class BudgetPlanFactCiFixtureCloseStore implements BudgetingReportSourceCloseStore
{
    public function __construct(private BudgetingReportSourceClose $close) {}

    public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
    {
        throw new LogicException('budget_plan_fact_ci_fixture_write_forbidden');
    }

    public function find(string $closeId): ?BudgetingReportSourceClose
    {
        return $closeId === $this->close->closeId ? $this->close : null;
    }
}
