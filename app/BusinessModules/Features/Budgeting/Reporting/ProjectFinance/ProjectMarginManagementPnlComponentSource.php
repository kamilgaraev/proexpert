<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

final readonly class ProjectMarginManagementPnlComponentSource extends ProjectFinanceManagementPnlComponentSource
{
    public function componentCode(): string
    {
        return 'project_margin';
    }
}
