<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectActReader;

final readonly class GetProjectActReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_act_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.act_description';
    public function __construct(AssistantProjectActReader $reader) { parent::__construct($reader); }
}
