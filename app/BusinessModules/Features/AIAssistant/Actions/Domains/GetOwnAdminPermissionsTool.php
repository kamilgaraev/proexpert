<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnAdminPermissionsReader;

final readonly class GetOwnAdminPermissionsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_admin_permissions';

    public const DESCRIPTION = 'ai_assistant_operational.own_permissions_description';

    public function __construct(AssistantOwnAdminPermissionsReader $reader)
    {
        parent::__construct($reader);
    }
}
