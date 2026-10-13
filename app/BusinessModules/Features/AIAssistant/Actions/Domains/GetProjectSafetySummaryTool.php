<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectSafetyReader;

final readonly class GetProjectSafetySummaryTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_safety_summary';
    public const DESCRIPTION = 'ai_assistant_operational.safety_description';
    public function __construct(AssistantProjectSafetyReader $reader) { parent::__construct($reader); }
}
