<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class HandoverBusinessRagSource extends LegalBusinessRagSource
{
    public function sourceType(): string { return 'handover_business'; }
}
