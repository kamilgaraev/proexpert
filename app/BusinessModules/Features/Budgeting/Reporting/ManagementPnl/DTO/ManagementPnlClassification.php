<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

use DomainException;

final readonly class ManagementPnlClassification
{
    public function __construct(public string $category)
    {
        if (!in_array($category, ['revenue', 'direct_non_labor_cost', 'direct_labor', 'operating_expense'], true)) {
            throw new DomainException('management_pnl_classification_invalid');
        }
    }
}
