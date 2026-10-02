<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

final class NavigationAssistantDomainTool extends AssistantDomainTool
{
    protected function operation(): string
    {
        return 'navigation';
    }
}