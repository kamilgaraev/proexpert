<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnRolesReader;

final readonly class GetOwnRolesTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_organization_roles';

    public const DESCRIPTION = 'ai_assistant_operational.own_roles_description';

    public function __construct(AssistantOwnRolesReader $reader)
    {
        parent::__construct($reader);
    }
}
