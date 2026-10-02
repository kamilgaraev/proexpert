<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class OperationsSiteRequestsRagSource extends OperationsBusinessRagSource
{
    public function sourceType(): string
    {
        return 'operations_site_requests';
    }
}
