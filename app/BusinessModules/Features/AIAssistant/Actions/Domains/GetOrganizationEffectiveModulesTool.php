<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationModulesReader;

final readonly class GetOrganizationEffectiveModulesTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_effective_modules';

    public const DESCRIPTION = 'ai_assistant_operational.modules_description';

    public function __construct(AssistantOrganizationModulesReader $reader)
    {
        parent::__construct($reader);
    }
}
