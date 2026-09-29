<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class MarketplaceBusinessRagSource extends SalesBusinessRagSource
{
    public function sourceType(): string { return 'contractor_marketplace'; }
}
