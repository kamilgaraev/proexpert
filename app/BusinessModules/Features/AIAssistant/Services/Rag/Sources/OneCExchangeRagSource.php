<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class OneCExchangeRagSource extends SafeMetadataRagSource
{
    public function sourceType(): string { return 'one_c_exchange'; }
}
