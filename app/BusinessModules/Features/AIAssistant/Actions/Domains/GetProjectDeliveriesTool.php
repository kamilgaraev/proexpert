<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDeliveriesReader;

final readonly class GetProjectDeliveriesTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_deliveries';

    public const DESCRIPTION = 'ai_assistant_operational.deliveries_description';

    public function __construct(AssistantProjectDeliveriesReader $reader)
    {
        parent::__construct($reader);
    }
}
