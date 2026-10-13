<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectSiteRequestReader;

final readonly class GetProjectSiteRequestQueueTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_site_request_queue';
    public const DESCRIPTION = 'ai_assistant_operational.site_description';
    public function __construct(AssistantProjectSiteRequestReader $reader) { parent::__construct($reader); }
}
