<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationContactsReader;

final readonly class GetOrganizationContactsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_contacts';

    public const DESCRIPTION = 'ai_assistant_operational.organization_contacts_description';

    public function __construct(AssistantOrganizationContactsReader $reader)
    {
        parent::__construct($reader);
    }
}
