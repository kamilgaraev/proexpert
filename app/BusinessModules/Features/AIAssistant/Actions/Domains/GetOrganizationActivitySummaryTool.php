<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationActivityReader;

final readonly class GetOrganizationActivitySummaryTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_activity_summary';
    public const DESCRIPTION = 'ai_assistant_operational.activity_description';
    public function __construct(AssistantOrganizationActivityReader $reader) { parent::__construct($reader); }
}
