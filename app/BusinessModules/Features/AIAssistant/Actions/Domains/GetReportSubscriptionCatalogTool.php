<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionCatalogReader;

final readonly class GetReportSubscriptionCatalogTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_report_subscription_catalog';

    public const DESCRIPTION = 'ai_assistant_operational.subscription_catalog_description';

    public function __construct(AssistantReportSubscriptionCatalogReader $reader)
    {
        parent::__construct($reader);
    }
}
