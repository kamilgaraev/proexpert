<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnProjectAccessReader;

final readonly class GetOwnProjectAccessTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_project_access';

    public const DESCRIPTION = 'ai_assistant_operational.access_description';

    public function __construct(AssistantOwnProjectAccessReader $reader)
    {
        parent::__construct($reader);
    }
}
