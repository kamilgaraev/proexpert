<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProductionScheduleReader;

final readonly class GetProjectProductionScheduleSummaryTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_production_schedule_summary';

    public const DESCRIPTION = 'ai_assistant_production_schedule.description';

    public function __construct(AssistantProjectProductionScheduleReader $reader)
    {
        parent::__construct($reader);
    }
}
