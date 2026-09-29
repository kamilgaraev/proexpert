<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class AdvanceAccountingRagSource extends ScopedFinanceTenderRagSource
{
    public function sourceType(): string
    {
        return 'advance_accounting';
    }
}
