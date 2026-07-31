<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Contracts;

interface PlanFactSourceSnapshotReport
{
    public function reportForProjectScope(array $input, array $projectIds): array;

    public function drillDownForProjectScope(array $input, array $projectIds): array;
}
