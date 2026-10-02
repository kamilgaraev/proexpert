<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class SalesSupplierRagSource extends SalesBusinessRagSource
{
    public function sourceType(): string { return 'sales_supplier_catalog'; }
}
