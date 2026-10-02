<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CrmBusinessRagSource extends SalesBusinessRagSource
{
    public function sourceType(): string { return 'crm_business'; }
}
