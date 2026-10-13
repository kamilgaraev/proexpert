<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectCameraReader;

final readonly class GetProjectCameraRosterTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_camera_roster';
    public const DESCRIPTION = 'ai_assistant_operational.camera_description';
    public function __construct(AssistantProjectCameraReader $reader) { parent::__construct($reader); }
}
