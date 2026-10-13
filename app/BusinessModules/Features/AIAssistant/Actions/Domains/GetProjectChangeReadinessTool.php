<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectChangeReader;

final readonly class GetProjectChangeReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_change_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.change_description';
    public function __construct(AssistantProjectChangeReader $reader) { parent::__construct($reader); }
}
