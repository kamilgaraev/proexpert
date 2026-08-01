<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Contracts;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;

interface BudgetingReportSourceCloseStore
{
    public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose;

    public function find(string $closeId): ?BudgetingReportSourceClose;
}
