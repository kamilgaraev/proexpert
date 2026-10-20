<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCalculationHealthReader;

final readonly class GetOrganizationCalculationHealthTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_calculation_health';

    public const DESCRIPTION = 'ai_assistant_calculation_health.description';

    public function __construct(AssistantOrganizationCalculationHealthReader $reader)
    {
        parent::__construct($reader);
    }
}
