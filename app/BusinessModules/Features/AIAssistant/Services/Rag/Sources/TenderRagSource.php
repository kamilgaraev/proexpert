<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class TenderRagSource extends ScopedFinanceTenderRagSource
{
    public function sourceType(): string
    {
        return 'tenders';
    }
}
