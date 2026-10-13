<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationProjectsReader;

final readonly class GetOrganizationProjectRosterTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_project_roster';
    public const DESCRIPTION = 'ai_assistant_operational.portfolio_description';
    public function __construct(AssistantOrganizationProjectsReader $reader) { parent::__construct($reader); }
}
