<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

final class ChangeBusinessRagSource extends LegalBusinessRagSource
{
    public function sourceType(): string { return 'change_business'; }
}
