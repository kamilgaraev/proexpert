<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class WorkforceRagSource extends DeclaredMetadataRagSource
{
    public function sourceType(): string
    {
        return 'workforce';
    }
}
