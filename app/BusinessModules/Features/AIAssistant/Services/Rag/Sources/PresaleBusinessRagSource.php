<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class PresaleBusinessRagSource extends SalesBusinessRagSource
{
    public function enabled(): bool { return false; }

    public function sourceType(): string { return 'presale_estimates'; }
}
