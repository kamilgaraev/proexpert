<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Contracts;

use App\Models\User;

interface ExactProjectFinanceSourceRead
{
    public function exactFinancialSourceRows(array $input, array $projectIds, User $actor): array;
}
