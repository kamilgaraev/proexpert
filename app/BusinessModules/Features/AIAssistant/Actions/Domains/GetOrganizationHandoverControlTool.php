<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationHandoverControlReader;

final readonly class GetOrganizationHandoverControlTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_handover_control';

    public const DESCRIPTION = 'ai_assistant_handover_control.description';

    public function __construct(AssistantOrganizationHandoverControlReader $reader)
    {
        parent::__construct($reader);
    }
}
