<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class BudgetingRagSource extends ScopedFinanceTenderRagSource
{
    public function sourceType(): string
    {
        return 'budgeting';
    }
}
