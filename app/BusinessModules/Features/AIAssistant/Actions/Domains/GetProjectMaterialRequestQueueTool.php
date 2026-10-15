<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectMaterialRequestReader;

final readonly class GetProjectMaterialRequestQueueTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_material_request_queue';
    public const DESCRIPTION = 'ai_assistant_operational.site_material_description';
    public function __construct(AssistantProjectMaterialRequestReader $reader) { parent::__construct($reader); }
}
