<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class CoreBusinessMoneyRagSource extends CoreBusinessRagSource
{
    public function sourceType(): string { return 'core_business_money'; }
}
