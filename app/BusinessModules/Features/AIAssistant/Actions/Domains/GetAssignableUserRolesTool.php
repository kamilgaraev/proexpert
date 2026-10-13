<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAssignableRolesReader;

final readonly class GetAssignableUserRolesTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_assignable_user_roles';

    public const DESCRIPTION = 'ai_assistant_operational.roles_description';

    public function __construct(AssistantAssignableRolesReader $reader)
    {
        parent::__construct($reader);
    }
}
