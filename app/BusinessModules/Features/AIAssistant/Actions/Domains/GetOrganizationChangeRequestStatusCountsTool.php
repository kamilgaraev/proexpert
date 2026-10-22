<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationChangeRequestStatusCountReader;

final readonly class GetOrganizationChangeRequestStatusCountsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_change_request_status_counts';

    public const DESCRIPTION = 'ai_assistant_change_request_counts.description';

    public function __construct(AssistantOrganizationChangeRequestStatusCountReader $reader)
    {
        parent::__construct($reader);
    }
}
