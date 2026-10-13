<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectProposalReader;

final readonly class GetProjectProposalReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_proposal_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.proposal_description';
    public function __construct(AssistantProjectProposalReader $reader) { parent::__construct($reader); }
}
