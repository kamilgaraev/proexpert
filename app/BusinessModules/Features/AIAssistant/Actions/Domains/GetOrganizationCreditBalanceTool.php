<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCreditBalanceReader;

final readonly class GetOrganizationCreditBalanceTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_credit_balance';

    public const DESCRIPTION = 'ai_assistant_operational.credit_balance_description';

    public function __construct(AssistantOrganizationCreditBalanceReader $reader)
    {
        parent::__construct($reader);
    }
}
