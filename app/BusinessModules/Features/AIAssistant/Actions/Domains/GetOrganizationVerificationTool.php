<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationVerificationReader;

final readonly class GetOrganizationVerificationTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_verification';

    public const DESCRIPTION = 'ai_assistant_operational.organization_verification_description';

    public function __construct(AssistantOrganizationVerificationReader $reader)
    {
        parent::__construct($reader);
    }
}
