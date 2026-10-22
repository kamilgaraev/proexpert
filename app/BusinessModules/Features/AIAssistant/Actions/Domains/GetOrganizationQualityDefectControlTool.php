<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationQualityDefectControlReader;

final readonly class GetOrganizationQualityDefectControlTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_quality_defect_control';

    public const DESCRIPTION = 'ai_assistant_quality_control.description';

    public function __construct(AssistantOrganizationQualityDefectControlReader $reader)
    {
        parent::__construct($reader);
    }
}
