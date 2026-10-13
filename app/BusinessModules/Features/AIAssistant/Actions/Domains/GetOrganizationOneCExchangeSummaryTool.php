<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOneCExchangeSummaryReader;

final readonly class GetOrganizationOneCExchangeSummaryTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_one_c_exchange_summary';
    public const DESCRIPTION = 'ai_assistant_operational.one_c_description';
    public function __construct(AssistantOneCExchangeSummaryReader $reader) { parent::__construct($reader); }
}
