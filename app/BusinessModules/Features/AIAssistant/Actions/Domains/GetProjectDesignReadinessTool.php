<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDesignReader;

final readonly class GetProjectDesignReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_design_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.design_description';
    public function __construct(AssistantProjectDesignReader $reader) { parent::__construct($reader); }
}
