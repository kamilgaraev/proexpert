<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDailyStaffReader;

final readonly class GetProjectDailyStaffTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_daily_staff';

    public const DESCRIPTION = 'ai_assistant_operational.daily_staff_description';

    public function __construct(AssistantProjectDailyStaffReader $reader)
    {
        parent::__construct($reader);
    }
}
