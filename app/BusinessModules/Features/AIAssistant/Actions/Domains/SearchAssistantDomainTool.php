<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

final class SearchAssistantDomainTool extends AssistantDomainTool
{
    protected function operation(): string
    {
        return 'search';
    }
}