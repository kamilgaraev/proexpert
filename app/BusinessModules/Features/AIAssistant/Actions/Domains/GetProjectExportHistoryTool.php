<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectExportHistoryReader;

final readonly class GetProjectExportHistoryTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_export_history';
    public const DESCRIPTION = 'ai_assistant_operational.export_history_description';
    public function __construct(AssistantProjectExportHistoryReader $reader) { parent::__construct($reader); }
}
