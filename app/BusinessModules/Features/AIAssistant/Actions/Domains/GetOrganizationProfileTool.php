<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationProfileReader;

final readonly class GetOrganizationProfileTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_business_profile';

    public const DESCRIPTION = 'ai_assistant_operational.organization_profile_description';

    public function __construct(AssistantOrganizationProfileReader $reader)
    {
        parent::__construct($reader);
    }
}
