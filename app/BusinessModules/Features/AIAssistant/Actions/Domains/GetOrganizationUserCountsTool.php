<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationUsersReader;

final readonly class GetOrganizationUserCountsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_user_counts';

    public const DESCRIPTION = 'ai_assistant_operational.user_counts_description';

    public function __construct(AssistantOrganizationUsersReader $reader)
    {
        parent::__construct($reader);
    }
}
