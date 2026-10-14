<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportWorkspaceReader;

final readonly class GetReportWorkspaceTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_report_workspace';

    public const DESCRIPTION = 'ai_assistant_operational.report_workspace_description';

    public function __construct(AssistantReportWorkspaceReader $reader)
    {
        parent::__construct($reader);
    }
}
