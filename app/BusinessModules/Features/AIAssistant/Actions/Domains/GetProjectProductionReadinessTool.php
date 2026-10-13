<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionReader;

final readonly class GetProjectProductionReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_production_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.production_description';
    public function __construct(AssistantProjectProductionReader $reader) { parent::__construct($reader); }
}
