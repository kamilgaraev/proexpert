<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionsReader;

final readonly class GetReportSubscriptionsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_report_subscriptions';

    public const DESCRIPTION = 'ai_assistant_operational.report_subscriptions_description';

    public function __construct(AssistantReportSubscriptionsReader $reader)
    {
        parent::__construct($reader);
    }
}
