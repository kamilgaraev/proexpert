<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectStaffReader;

final readonly class GetProjectStaffRosterTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_staff_roster';
    public const DESCRIPTION = 'ai_assistant_operational.staff_description';
    public function __construct(AssistantProjectStaffReader $reader) { parent::__construct($reader); }
}
