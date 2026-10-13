<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationPackagesReader;

final readonly class GetOrganizationActivePackagesTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_active_packages';

    public const DESCRIPTION = 'ai_assistant_operational.packages_description';

    public function __construct(AssistantOrganizationPackagesReader $reader)
    {
        parent::__construct($reader);
    }
}
