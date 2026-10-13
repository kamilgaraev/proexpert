<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectCrmReader;

final readonly class GetProjectCrmReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_crm_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.crm_description';
    public function __construct(AssistantProjectCrmReader $reader) { parent::__construct($reader); }
}
