<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExecutiveReader;

final readonly class GetProjectExecutiveReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_executive_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.executive_description';
    public function __construct(AssistantProjectExecutiveReader $reader) { parent::__construct($reader); }
}
