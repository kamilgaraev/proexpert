<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class OperationsMachineryRagSource extends OperationsBusinessRagSource
{
    public function sourceType(): string
    {
        return 'operations_machinery';
    }
}
